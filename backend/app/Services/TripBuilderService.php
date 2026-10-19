<?php

namespace App\Services;

use App\Models\Position;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

class TripBuilderService
{
    /**
     * Velocidad (km/h) por debajo de la cual se considera que la unidad está detenida.
     * No se usa speed == 0 porque el GPS reporta 1 o 2 km/h de ruido estando parada.
     */
    protected const IDLE_SPEED_KMH = 3;

    /**
     * Radio (km) de la regla de permanencia. Con datos reales de Kevs1 (19 oct 2026): en la
     * bajada de pasajeros de Margaritaville la unidad maniobró unos 4 minutos dentro de
     * ~150 m (entrada del hotel), sin quedarse quieta 3 minutos seguidos en ningún punto.
     */
    protected const DWELL_RADIUS_KM = 0.2;

    /**
     * Segundos que la ignición debe seguir en false para considerar el motor apagado.
     * Filtra señales que parpadean: Kevs1 tenía la ignición por voltaje con umbral de 13.2 V,
     * y su alternador carga justo alrededor de ese valor, así que ignition cambiaba de
     * true a false cada 1 a 5 segundos, incluso a 56 km/h (ver docs/piloto-fmc920.md).
     */
    protected const IGNITION_DEBOUNCE_SECONDS = 60;

    /**
     * Segundos de movimiento sostenido requeridos para confirmar inicio de viaje.
     */
    protected int $startConfirmationSeconds;

    /**
     * Minutos de motor apagado sostenido requeridos para confirmar fin de viaje.
     * Calibrado con datos reales: una parada de ~8 min con motor apagado NO es fin de viaje,
     * una parada de >1.5h sí lo es. Punto de partida conservador: 15 minutos.
     * Con la regla de permanencia casi siempre se cierra antes por esa regla; esta queda
     * como respaldo.
     */
    protected int $endConfirmationMinutes;

    /**
     * Minutos dentro del radio DWELL_RADIUS_KM requeridos para cerrar el viaje
     * (regla de permanencia). No depende de la ignición, así que funciona aunque la señal
     * de una unidad no sea confiable. Regla temporal (19 oct 2026): el corte definitivo
     * vendrá de ligar los viajes con las órdenes de servicio.
     */
    protected int $dwellEndMinutes;

    protected ZoneMatchingService $zoneMatcher;

    public function __construct(
        int $startConfirmationSeconds = 30,
        int $endConfirmationMinutes = 15,
        ?ZoneMatchingService $zoneMatcher = null,
        int $dwellEndMinutes = 3
    ) {
        $this->startConfirmationSeconds = $startConfirmationSeconds;
        $this->endConfirmationMinutes = $endConfirmationMinutes;
        $this->zoneMatcher = $zoneMatcher ?? new ZoneMatchingService();
        $this->dwellEndMinutes = $dwellEndMinutes;
    }

    /**
     * Procesa las posiciones de un vehículo en un rango de tiempo y crea los trips detectados.
     * Solo usa posiciones con valid = true.
     *
     * Si un viaje ya guardado empezó antes de $from y sigue abierto en $from, el rango se
     * extiende hasta su inicio. Así el viaje se recalcula completo y no aparece una versión
     * cortada que empiece justo en $from.
     *
     * @return Collection<Trip>
     */
    public function buildForVehicle(Vehicle $vehicle, string $from, string $to): Collection
    {
        $spanning = Trip::where('vehicle_id', $vehicle->id)
            ->where('data_source', 'real')
            ->where('started_at', '<', $from)
            ->where('ended_at', '>=', $from)
            ->orderBy('started_at')
            ->first();

        if ($spanning) {
            $from = $spanning->started_at->toDateTimeString();
        }

        $positions = Position::where('vehicle_id', $vehicle->id)
            ->where('valid', true)
            ->whereBetween('device_time', [$from, $to])
            ->orderBy('device_time')
            ->get();

        return $this->build($vehicle, $positions);
    }

    /**
     * Recorre las posiciones en orden y detecta los viajes.
     *
     * Inicio: motor encendido y movimiento sostenido por startConfirmationSeconds.
     * Fin, lo que pase primero:
     *   - Regla 1: motor apagado por endConfirmationMinutes.
     *   - Regla 2 (permanencia): la unidad se queda dentro de DWELL_RADIUS_KM por
     *     dwellEndMinutes, por ejemplo al dejar pasajeros en un hotel.
     *
     * @param Collection<Position> $positions Ya ordenadas por device_time, del mismo vehículo.
     * @return Collection<Trip>
     */
    public function build(Vehicle $vehicle, Collection $positions): Collection
    {
        $trips = collect();

        $tripStart = null;         // Position donde arrancó el viaje actual
        $tripPositions = [];       // Posiciones acumuladas del viaje en curso
        $endCandidate = null;      // Última lectura con motor encendido antes de apagarse (candidato a fin, regla 1)
        $dwellAnchor = null;       // Position donde se detuvo (candidato a fin, regla 2)
        $movingSince = null;       // Position donde empezó a evaluarse el posible inicio
        $hasMovedInWindow = false; // true si dentro de la ventana actual hubo movimiento real
        $lastIgnitionOn = null;    // Última lectura con ignition = true, para el filtro de ignición
        $lastClosePoint = null;    // Donde se cerró el viaje anterior

        foreach ($positions as $position) {
            $ignitionOn = $this->isIgnitionOn($position, $lastIgnitionOn);

            if ($position->ignition) {
                $lastIgnitionOn = $position;
            }

            if ($tripStart === null) {
                // Buscando inicio de viaje.
                // No exigimos speed > 0 en CADA lectura (un alto o semáforo con el motor
                // encendido no debe reiniciar el conteo). Solo exigimos que el motor
                // esté encendido de forma sostenida y que haya habido movimiento real
                // en algún punto de esa ventana de tiempo.
                if (!$ignitionOn) {
                    $movingSince = null;
                    $hasMovedInWindow = false;
                    continue;
                }

                // Mientras siga en el radio donde terminó el viaje anterior (maniobras en la
                // entrada del hotel, en el estacionamiento) no cuenta como inicio.
                $insideLastStop = $lastClosePoint !== null
                    && $this->distanceKm($lastClosePoint, $position) <= self::DWELL_RADIUS_KM;

                $stillWaiting = !$hasMovedInWindow && $position->speed < self::IDLE_SPEED_KMH;

                if ($movingSince === null || $insideLastStop || $stillWaiting) {
                    // La ventana se recorre a la lectura más reciente mientras siga detenida
                    // o dentro del radio. Así el viaje empieza cuando de verdad sale y no
                    // carga la espera.
                    $movingSince = $position;
                    $hasMovedInWindow = !$insideLastStop && $position->speed >= self::IDLE_SPEED_KMH;
                    continue;
                }

                if ($position->speed > 0) {
                    $hasMovedInWindow = true;
                }

                // abs(): Carbon 3 regresa diffInSeconds con signo (puede ser negativo
                // según el orden de los objetos comparados). Siempre queremos la
                // magnitud del tiempo transcurrido, sin importar el orden.
                $elapsed = abs($position->device_time->diffInSeconds($movingSince->device_time));

                if ($hasMovedInWindow && $elapsed >= $this->startConfirmationSeconds) {
                    $tripStart = $movingSince;
                    $tripPositions = [$movingSince, $position];
                    $movingSince = null;
                    $hasMovedInWindow = false;
                    $lastClosePoint = null;
                }

                continue;
            }

            // Viaje en curso
            $tripPositions[] = $position;
            $closingPoint = null;

            // Regla 1: motor apagado sostenido.
            if (!$ignitionOn) {
                if ($endCandidate === null) {
                    // El fin es la última lectura con el motor encendido, no la lectura en la
                    // que el filtro confirmó el apagado (que llega IGNITION_DEBOUNCE_SECONDS después).
                    $endCandidate = $lastIgnitionOn ?? $position;
                }

                $minutesSinceCandidate = abs($position->device_time->diffInMinutes($endCandidate->device_time));

                if ($minutesSinceCandidate >= $this->endConfirmationMinutes) {
                    $closingPoint = $endCandidate;
                }
            } else {
                // El motor volvió a encenderse antes de confirmarse el fin: se cancela el candidato.
                $endCandidate = null;
            }

            // Regla 2: permanencia dentro del radio.
            if ($closingPoint === null) {
                if ($dwellAnchor !== null && $this->distanceKm($dwellAnchor, $position) > self::DWELL_RADIUS_KM) {
                    // Salió del radio antes de cumplir el tiempo: era tráfico o un semáforo.
                    $dwellAnchor = null;
                }

                if ($dwellAnchor === null) {
                    if ($position->speed < self::IDLE_SPEED_KMH) {
                        $dwellAnchor = $position;
                    }
                } else {
                    $dwellSeconds = abs($position->device_time->diffInSeconds($dwellAnchor->device_time));

                    if ($dwellSeconds >= $this->dwellEndMinutes * 60) {
                        $closingPoint = $dwellAnchor;
                    }
                }
            }

            if ($closingPoint !== null) {
                $trips->push($this->closeTrip($vehicle, $tripPositions, $closingPoint));
                $lastClosePoint = $closingPoint;

                // Reiniciar estado para buscar el siguiente viaje
                $tripStart = null;
                $tripPositions = [];
                $endCandidate = null;
                $dwellAnchor = null;
                $movingSince = null;
                $hasMovedInWindow = false;
            }
        }

        // Si se acabaron las posiciones y todavía había un viaje abierto, se guarda de forma
        // provisional con la mejor información disponible: el candidato a fin si ya había uno,
        // o si no, la última posición conocida. Puede ser un viaje en curso, o uno cuyas
        // posiciones todavía no terminan de llegar (el FMC920 puede entregarlas con horas de
        // retraso, ver docs/piloto-fmc920.md). No queda cortado: closeTrip() lo recalcula y
        // lo actualiza en las siguientes corridas, cuando ya haya más posiciones.
        if ($tripStart !== null && !empty($tripPositions)) {
            $lastPosition = end($tripPositions);
            $closingPoint = $endCandidate ?? $lastPosition;
            $trips->push($this->closeTrip($vehicle, $tripPositions, $closingPoint));
        }

        return $trips;
    }

    /**
     * Ignición con filtro: el motor cuenta como encendido si esta lectura dice true, o si
     * hubo una lectura con true hace menos de IGNITION_DEBOUNCE_SECONDS. Así un parpadeo
     * de la señal no se toma como motor apagado.
     */
    protected function isIgnitionOn(Position $position, ?Position $lastIgnitionOn): bool
    {
        if ($position->ignition) {
            return true;
        }

        if ($lastIgnitionOn === null) {
            return false;
        }

        $secondsSinceOn = abs($position->device_time->diffInSeconds($lastIgnitionOn->device_time));

        return $secondsSinceOn < self::IGNITION_DEBOUNCE_SECONDS;
    }

    /**
     * Calcula las métricas del viaje y lo guarda en trips.
     * ended_at usa el momento del candidato a fin, no el momento de confirmación,
     * para no inflar la duración con el tiempo de espera de confirmación.
     *
     * Idempotente y autocorrectivo: la llave es vehículo + started_at. Si el viaje ya existe,
     * se recalcula y se actualiza con las posiciones actuales, en lugar de dejarlo como estaba.
     * Así, un viaje guardado de forma provisional (cortado porque faltaban posiciones) se
     * completa cuando trips:build vuelve a correr sobre un rango que lo incluye.
     *
     * Si el inicio cambió entre corridas (las posiciones llegan tarde y por partes, ver
     * docs/piloto-fmc920.md), la versión anterior queda con otro started_at. Por eso, al
     * guardar, se borran los otros viajes del mismo vehículo que se enciman en horario:
     * una unidad no puede estar en dos viajes a la vez, así que son versiones viejas
     * del mismo viaje.
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

        $distanceKm = $this->calculateTripDistanceKm($tripPositions);
        [$stopsCount, $stoppedSeconds] = $this->calculateStops($tripPositions);

        $originZone = $this->zoneMatcher->resolve($first->latitude, $first->longitude);
        $destinationZone = $this->zoneMatcher->resolve($last->latitude, $last->longitude);

        $trip = Trip::updateOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'started_at' => $first->device_time,
            ],
            [
                'origin_zone_id' => $originZone?->id,
                'destination_zone_id' => $destinationZone?->id,
                'ended_at' => $last->device_time,
                'duration_seconds' => abs($last->device_time->diffInSeconds($first->device_time)),
                'distance_km' => $distanceKm,
                'average_speed' => $tripPositions->avg('speed'),
                'max_speed' => $tripPositions->max('speed'),
                'stops_count' => $stopsCount,
                'stopped_seconds' => $stoppedSeconds,
                'data_source' => 'real',
            ]
        );

        Trip::where('vehicle_id', $vehicle->id)
            ->where('data_source', 'real')
            ->where('id', '!=', $trip->id)
            ->where('started_at', '<', $trip->ended_at)
            ->where('ended_at', '>', $trip->started_at)
            ->delete();

        return $trip;
    }

    /**
     * Distancia del viaje en km.
     *
     * Prioridad: odómetro del FMC920 (diferencia entre la última y la primera posición).
     * El dispositivo lo calcula internamente, así que no depende de cada cuánto se guardan
     * posiciones: sigue siendo exacto si la configuración espacia los registros.
     * Si falta o es inconsistente (negativo, por ejemplo por un reinicio del contador),
     * se usa la suma Haversine entre posiciones.
     *
     * Validado el 6 de octubre de 2026: en 4 viajes reales, odómetro, Haversine y
     * totalDistance de Traccar coincidieron con diferencias de 10 m o menos
     * (docs/piloto-fmc920.md, sección 4.6).
     */
    protected function calculateTripDistanceKm(Collection $positions): float
    {
        // getAttribute() explícito: la columna se llama "attributes", igual que la
        // propiedad interna de Eloquent, y así no hay ambigüedad.
        $startOdometer = $positions->first()->getAttribute('attributes')['odometer'] ?? null;
        $endOdometer = $positions->last()->getAttribute('attributes')['odometer'] ?? null;

        if (is_numeric($startOdometer) && is_numeric($endOdometer) && $endOdometer >= $startOdometer) {
            return round(($endOdometer - $startOdometer) / 1000, 3);
        }

        return $this->calculateDistanceKm($positions);
    }

    /**
     * Distancia total sumando la distancia Haversine entre posiciones consecutivas.
     * Respaldo de calculateTripDistanceKm() cuando no hay odómetro confiable.
     */
    protected function calculateDistanceKm(Collection $positions): float
    {
        $total = 0.0;
        $prev = null;

        foreach ($positions as $position) {
            if ($prev !== null) {
                $total += $this->distanceKm($prev, $position);
            }
            $prev = $position;
        }

        return round($total, 3);
    }

    protected function distanceKm(Position $a, Position $b): float
    {
        return $this->haversineKm($a->latitude, $a->longitude, $b->latitude, $b->longitude);
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
     * Cuenta paradas intermedias (velocidad menor a IDLE_SPEED_KMH sostenida) dentro del viaje
     * y el total de segundos detenido. No cuenta como parada el cierre del viaje mismo.
     * No usa la ignición: en unidades con la señal ruidosa, cada parpadeo cortaría la parada.
     *
     * @return array{0: int, 1: int} [stops_count, stopped_seconds]
     */
    protected function calculateStops(Collection $positions): array
    {
        $stopsCount = 0;
        $stoppedSeconds = 0;
        $stopStart = null;

        foreach ($positions as $position) {
            $isStopped = $position->speed < self::IDLE_SPEED_KMH;

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