<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Position;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Pone nombre de lugar al inicio y al fin de cada viaje: "Valentin Imperial Maya lobby",
 * "Aeropuerto T3 salidas". Lo usan la lista de viajes de hoy, el historial y los reportes.
 *
 * Consulta para todos los viajes juntos (posiciones, lugares y viajes anteriores), no por
 * viaje, y en bloques: SQLite tiene un límite de variables por consulta, y un reporte de dos
 * meses de varias unidades puede traer miles de viajes.
 */
class TripPlaceResolver
{
    /**
     * Un viaje empieza cuando la unidad sale del radio de 200 m donde terminó el anterior
     * (TripBuilderService::LEAVE_RADIUS_KM), así que su primer punto casi nunca cae dentro
     * del lobby. Si empieza cerca de donde terminó el anterior, su origen es ese destino.
     */
    protected const ORIGIN_FROM_PREVIOUS_METERS = 300;

    // Horas por consulta: muy por debajo del límite de variables de SQLite
    protected const CHUNK = 400;

    /** @var Collection<int, Place>|null */
    protected ?Collection $places = null;

    /**
     * @param Collection<int, Trip> $trips
     * @return array<int, array{origin_place: ?string, destination_place: ?string}> por id de viaje
     */
    public function resolve(Collection $trips): array
    {
        if ($trips->isEmpty()) {
            return [];
        }

        $positions = $this->positionsAt(
            $trips->flatMap(fn (Trip $t) => [[$t->vehicle_id, $t->started_at], [$t->vehicle_id, $t->ended_at]])
        );

        $places = $this->places ??= Place::where('is_active', true)->get(['name', 'latitude', 'longitude', 'radius_m']);

        // El viaje anterior de cada uno, aunque no venga en la lista (el primero del día, por ejemplo)
        $previous = $this->previousTrips($trips);
        $previousEnds = $this->positionsAt($previous->map(fn (Trip $t) => [$t->vehicle_id, $t->ended_at])->values());

        $result = [];

        foreach ($trips as $trip) {
            $start = $positions->get($this->key($trip->vehicle_id, $trip->started_at));
            $end = $positions->get($this->key($trip->vehicle_id, $trip->ended_at));

            $origin = $start ? $this->placeNameAt($places, (float) $start->latitude, (float) $start->longitude) : null;

            // Sin lugar al inicio: si arrancó cerca de donde terminó el viaje anterior, viene de ahí
            $before = $previous->get($trip->id);
            $beforeEnd = $before ? $previousEnds->get($this->key($before->vehicle_id, $before->ended_at)) : null;

            if ($origin === null && $start && $beforeEnd) {
                $gap = $this->meters((float) $start->latitude, (float) $start->longitude, (float) $beforeEnd->latitude, (float) $beforeEnd->longitude);

                if ($gap <= self::ORIGIN_FROM_PREVIOUS_METERS) {
                    $origin = $this->placeNameAt($places, (float) $beforeEnd->latitude, (float) $beforeEnd->longitude);
                }
            }

            $result[$trip->id] = [
                'origin_place' => $origin,
                'destination_place' => $end ? $this->placeNameAt($places, (float) $end->latitude, (float) $end->longitude) : null,
            ];
        }

        return $result;
    }

    /**
     * Nombre del lugar activo más cercano que contiene el punto, o null.
     *
     * @param Collection<int, Place> $places
     */
    public function placeNameAt(Collection $places, float $lat, float $lng): ?string
    {
        $best = null;
        $bestMeters = INF;

        foreach ($places as $place) {
            $meters = $this->meters($lat, $lng, $place->latitude, $place->longitude);

            if ($meters <= $place->radius_m && $meters < $bestMeters) {
                $best = $place->name;
                $bestMeters = $meters;
            }
        }

        return $best;
    }

    /**
     * Posiciones exactas en esos momentos, por "unidad|timestamp". Consulta en bloques.
     *
     * @param Collection<int, array{0: int, 1: \DateTimeInterface}> $moments
     * @return Collection<string, Position>
     */
    protected function positionsAt(Collection $moments): Collection
    {
        $found = collect();

        foreach ($moments->chunk(self::CHUNK) as $chunk) {
            Position::whereIn('vehicle_id', $chunk->pluck(0)->unique()->values())
                ->whereIn('device_time', $chunk->pluck(1)->unique()->values())
                ->get(['vehicle_id', 'device_time', 'latitude', 'longitude'])
                ->each(fn (Position $p) => $found->put($this->key($p->vehicle_id, $p->device_time), $p));
        }

        return $found;
    }

    /**
     * El viaje inmediatamente anterior (misma unidad) de cada viaje.
     *
     * @param Collection<int, Trip> $trips
     * @return Collection<int, Trip> por id del viaje
     */
    protected function previousTrips(Collection $trips): Collection
    {
        // Todos los viajes de esas unidades desde el día anterior al primero de la lista
        $all = Trip::whereIn('vehicle_id', $trips->pluck('vehicle_id')->unique()->values())
            ->where('data_source', 'real')
            ->where('started_at', '>=', $trips->min('started_at')->copy()->subDay())
            ->where('started_at', '<=', $trips->max('started_at'))
            ->orderBy('vehicle_id')
            ->orderBy('started_at')
            ->get(['id', 'vehicle_id', 'started_at', 'ended_at']);

        $previous = collect();
        $last = [];

        foreach ($all as $trip) {
            if (isset($last[$trip->vehicle_id])) {
                $previous->put($trip->id, $last[$trip->vehicle_id]);
            }
            $last[$trip->vehicle_id] = $trip;
        }

        return $previous;
    }

    protected function key(int $vehicleId, \DateTimeInterface $time): string
    {
        return $vehicleId . '|' . $time->getTimestamp();
    }

    protected function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}