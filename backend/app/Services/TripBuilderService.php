<?php

namespace App\Services;

use App\Models\Position;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

class TripBuilderService
{
    /**
     * Segundos de velocidad > 0 sostenida requeridos para confirmar inicio de viaje.
     */
    protected int $startConfirmationSeconds;

    /**
     * Minutos de ignition = false sostenido requeridos para confirmar fin de viaje.
     * Calibrado con datos reales: una parada de ~8 min con motor apagado NO es fin de viaje,
     * una parada de >1.5h sí lo es. Punto de partida conservador: 15 minutos.
     */
    protected int $endConfirmationMinutes;

    public function __construct(int $startConfirmationSeconds = 30, int $endConfirmationMinutes = 15)
    {
        $this->startConfirmationSeconds = $startConfirmationSeconds;
        $this->endConfirmationMinutes = $endConfirmationMinutes;
    }

    /**
     * Procesa las posiciones de un vehículo en un rango de tiempo y crea los trips detectados.
     * Solo usa posiciones con valid = true.
     *
     * @return Collection<Trip>
     */
    public function buildForVehicle(Vehicle $vehicle, string $from, string $to): Collection
    {
        $positions = Position::where('vehicle_id', $vehicle->id)
            ->where('valid', true)
            ->whereBetween('device_time', [$from, $to])
            ->orderBy('device_time')
            ->get();

        return $this->build($vehicle, $positions);
    }

    /**
     * @param Collection<Position> $positions Ya ordenadas por device_time, del mismo vehículo.
     * @return Collection<Trip>
     */
    public function build(Vehicle $vehicle, Collection $positions): Collection
    {
        $trips = collect();

        $tripStart = null;         // Position donde arrancó el viaje actual
        $tripPositions = [];       // Posiciones acumuladas del viaje en curso
        $endCandidate = null;      // Position donde ignition pasó a false (candidato a fin)
        $movingSince = null;       // Position donde empezó a evaluarse el posible inicio (ignition true)
        $hasMovedInWindow = false; // true si dentro de la ventana actual hubo al menos una lectura con speed > 0

        foreach ($positions as $position) {
            if ($tripStart === null) {
                // Buscando inicio de viaje.
                // No exigimos speed > 0 en CADA lectura (un alto/semáforo con el motor
                // encendido no debe reiniciar el conteo) — solo exigimos que el motor
                // esté encendido de forma sostenida y que haya habido movimiento real
                // en algún punto de esa ventana de tiempo.
                if ($position->ignition) {
                    if ($movingSince === null) {
                        $movingSince = $position;
                        $hasMovedInWindow = $position->speed > 0;
                    } else {
                        if ($position->speed > 0) {
                            $hasMovedInWindow = true;
                        }

                        // abs(): Carbon 3 regresa diffInSeconds con signo (puede ser negativo
                        // según el orden de los objetos comparados) — siempre queremos la
                        // magnitud del tiempo transcurrido, sin importar el orden.
                        $elapsed = abs($position->device_time->diffInSeconds($movingSince->device_time));

                        if ($hasMovedInWindow && $elapsed >= $this->startConfirmationSeconds) {
                            $tripStart = $movingSince;
                            $tripPositions = [$movingSince, $position];
                            $movingSince = null;
                        }
                    }
                } else {
                    $movingSince = null;
                }
                continue;
            }

            // Viaje en curso
            $tripPositions[] = $position;

            if (!$position->ignition) {
                if ($endCandidate === null) {
                    $endCandidate = $position;
                }

                $minutesSinceCandidate = abs($position->device_time->diffInMinutes($endCandidate->device_time));

                if ($minutesSinceCandidate >= $this->endConfirmationMinutes) {
                    $trips->push($this->closeTrip($vehicle, $tripPositions, $endCandidate));

                    // Reiniciar estado para buscar el siguiente viaje
                    $tripStart = null;
                    $tripPositions = [];
                    $endCandidate = null;
                    $movingSince = null;
                    $hasMovedInWindow = false;
                }
            } else {
                // ignition volvió a true antes de confirmarse el fin: se cancela el candidato
                $endCandidate = null;
            }
        }

        // Si se acabaron las posiciones y todavía había un viaje abierto (nunca se confirmó
        // el fin dentro de la ventana consultada), lo cerramos igual con la mejor información
        // disponible: el candidato a fin si ya había uno detectado, o si no, la última
        // posición conocida. Sin esto, un viaje que sigue "vivo" al final del rango de fechas
        // se pierde en silencio y $trips regresa vacío aunque sí hubo movimiento real.
        if ($tripStart !== null && !empty($tripPositions)) {
            $lastPosition = end($tripPositions);
            $closingPoint = $endCandidate ?? $lastPosition;
            $trips->push($this->closeTrip($vehicle, $tripPositions, $closingPoint));
        }

        return $trips;
    }

    /**
     * Calcula las métricas del viaje y crea el registro en trips.
     * ended_at usa el momento del candidato a fin, no el momento de confirmación,
     * para no inflar la duración con el tiempo de espera de confirmación.
     *
     * @param Position[] $positions
     */
    protected function closeTrip(Vehicle $vehicle, array $positions, Position $endCandidate): Trip
{
    $tripPositions = collect($positions)->filter(
        fn (Position $p) => $p->device_time->lte($endCandidate->device_time)
    )->values();

    $first = $tripPositions->first();
    $last = $tripPositions->last();

    // Idempotencia: si ya existe un trip para este vehículo con el mismo inicio,
    // no lo volvemos a crear (puede pasar si trips:build corre sobre un rango
    // que se traslapa con uno ya procesado).
    $existing = Trip::where('vehicle_id', $vehicle->id)
        ->where('started_at', $first->device_time)
        ->first();

    if ($existing) {
        return $existing;
    }

    $distanceKm = $this->calculateDistanceKm($tripPositions);
    [$stopsCount, $stoppedSeconds] = $this->calculateStops($tripPositions);

    return Trip::create([
        'vehicle_id' => $vehicle->id,
        'origin_zone_id' => null,
        'destination_zone_id' => null,
        'started_at' => $first->device_time,
        'ended_at' => $last->device_time,
        'duration_seconds' => abs($last->device_time->diffInSeconds($first->device_time)),
        'distance_km' => $distanceKm,
        'average_speed' => $tripPositions->avg('speed'),
        'max_speed' => $tripPositions->max('speed'),
        'stops_count' => $stopsCount,
        'stopped_seconds' => $stoppedSeconds,
        'data_source' => 'real',
    ]);
}
    /**
     * Distancia total sumando la distancia Haversine entre posiciones consecutivas.
     */
    protected function calculateDistanceKm(Collection $positions): float
    {
        $total = 0.0;
        $prev = null;

        foreach ($positions as $position) {
            if ($prev !== null) {
                $total += $this->haversineKm(
                    $prev->latitude, $prev->longitude,
                    $position->latitude, $position->longitude
                );
            }
            $prev = $position;
        }

        return round($total, 3);
    }

    protected function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Cuenta paradas intermedias (speed = 0 sostenido con ignition = true) dentro del viaje
     * y el total de segundos detenido. No cuenta como parada el cierre del viaje mismo.
     *
     * @return array{0: int, 1: int} [stops_count, stopped_seconds]
     */
    protected function calculateStops(Collection $positions): array
    {
        $stopsCount = 0;
        $stoppedSeconds = 0;
        $stopStart = null;

        foreach ($positions as $position) {
            $isStopped = $position->speed == 0 && $position->ignition;

            if ($isStopped) {
                if ($stopStart === null) {
                    $stopStart = $position;
                }
            } else {
                if ($stopStart !== null) {
                    $duration = abs($position->device_time->diffInSeconds($stopStart->device_time));
                    if ($duration >= 30) { // ignorar paradas triviales (semáforo corto, ruido de GPS)
                        $stopsCount++;
                        $stoppedSeconds += $duration;
                    }
                    $stopStart = null;
                }
            }
        }

        return [$stopsCount, $stoppedSeconds];
    }
}