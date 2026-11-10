<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Position;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Pone nombre de lugar al inicio y al fin de cada viaje: "Valentin Imperial Maya lobby",
 * "Aeropuerto T3 salidas". Lo usan la lista de viajes de hoy, el historial, los reportes y
 * la operación automática.
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

    /**
     * Entre un viaje y el siguiente la unidad se puede mover dentro del radio de salida sin
     * que eso sea un viaje (TripBuilderService::LEAVE_RADIUS_KM). 27 oct: se detuvo en la
     * entrada de Punta Cancún, entró al lobby de Coral Beach, recogió y salió; el viaje
     * terminó en la entrada y el siguiente empezó en la calle. Si un extremo no cae en un
     * lugar, se busca el lugar donde estuvo la unidad en ese hueco, si duró menos que esto.
     */
    protected const GAP_MAX_SECONDS = 3600;

    /** @var Collection<int, Place>|null */
    protected ?Collection $places = null;

    /**
     * @param Collection<int, Trip> $trips
     * @return array<int, array{origin_place: ?string, destination_place: ?string}> por id de viaje
     */
    public function resolve(Collection $trips): array
    {
        return array_map(fn (array $ends) => [
            'origin_place' => $ends['origin']['name'],
            'destination_place' => $ends['destination']['name'],
        ], $this->ends($trips));
    }

    /**
     * Inicio y fin de cada viaje con el lugar donde cayeron (si cayeron en uno) y el punto
     * exacto. Lo usa la operación automática, que necesita el tipo de lugar (terminal,
     * hotel, base) y las coordenadas de los puntos sin nombre.
     *
     * @param Collection<int, Trip> $trips
     * @return array<int, array{
     *   origin: array{lat: ?float, lng: ?float, name: ?string, type: ?string, place_id: ?int},
     *   destination: array{lat: ?float, lng: ?float, name: ?string, type: ?string, place_id: ?int}
     * }> por id de viaje
     */
    public function ends(Collection $trips): array
    {
        if ($trips->isEmpty()) {
            return [];
        }

        $positions = $this->positionsAt(
            $trips->flatMap(fn (Trip $t) => [[$t->vehicle_id, $t->started_at], [$t->vehicle_id, $t->ended_at]])
        );

        $places = $this->places();

        // El viaje anterior y el siguiente de cada uno, aunque no vengan en la lista
        [$previous, $next] = $this->neighbours($trips);
        $previousEnds = $this->positionsAt($previous->map(fn (Trip $t) => [$t->vehicle_id, $t->ended_at])->values());

        $result = [];

        foreach ($trips as $trip) {
            $start = $positions->get($this->key($trip->vehicle_id, $trip->started_at));
            $end = $positions->get($this->key($trip->vehicle_id, $trip->ended_at));

            $before = $previous->get($trip->id);
            $after = $next->get($trip->id);

            // Primero el lugar donde estuvo detenida antes de arrancar (la última hora). El punto
            // de inicio queda a unos 200 m de donde estaba (el viaje empieza al salir de ese radio)
            // y en la zona hotelera ese punto puede caer en el hotel de al lado (8 nov: salió del
            // Temptation y el inicio cayó en Cancun Bay; 7 nov: Riu Caribe y Sunset Marina).
            $origin = $before
                ? $this->placeInGap($places, $trip->vehicle_id, $before->ended_at, $trip->started_at, true)
                : null;
            $origin ??= $this->point($places, $start);

            // Si no, si arrancó cerca de donde terminó el viaje anterior, viene de ahí
            $beforeEnd = $before ? $previousEnds->get($this->key($before->vehicle_id, $before->ended_at)) : null;

            if ($origin['name'] === null && $start && $beforeEnd) {
                $gap = $this->meters((float) $start->latitude, (float) $start->longitude, (float) $beforeEnd->latitude, (float) $beforeEnd->longitude);

                if ($gap <= self::ORIGIN_FROM_PREVIOUS_METERS) {
                    $origin = $this->point($places, $beforeEnd);
                }
            }

            // Sin lugar al final: el lugar donde estuvo antes del siguiente viaje
            $destination = $this->point($places, $end);
            if ($destination['name'] === null && $after) {
                $destination = $this->placeInGap($places, $trip->vehicle_id, $trip->ended_at, $after->started_at) ?? $destination;
            }

            $result[$trip->id] = [
                'origin' => $origin,
                'destination' => $destination,
            ];
        }

        return $result;
    }

    /**
     * @return Collection<int, Place>
     */
    public function places(): Collection
    {
        return $this->places ??= Place::where('is_active', true)->get(['id', 'name', 'type', 'latitude', 'longitude', 'radius_m']);
    }

    /**
     * @param Collection<int, Place> $places
     */
    protected function point(Collection $places, ?Position $position): array
    {
        if (!$position) {
            return ['lat' => null, 'lng' => null, 'name' => null, 'type' => null, 'place_id' => null];
        }

        $lat = (float) $position->latitude;
        $lng = (float) $position->longitude;
        $place = $this->placeAt($places, $lat, $lng);

        return ['lat' => $lat, 'lng' => $lng, 'name' => $place?->name, 'type' => $place?->type, 'place_id' => $place?->id];
    }

    /**
     * Lugar activo más cercano que contiene el punto, o null.
     *
     * @param Collection<int, Place> $places
     */
    public function placeAt(Collection $places, float $lat, float $lng): ?Place
    {
        $best = null;
        $bestMeters = INF;

        foreach ($places as $place) {
            $meters = $this->meters($lat, $lng, $place->latitude, $place->longitude);

            if ($meters <= $place->radius_m && $meters < $bestMeters) {
                $best = $place;
                $bestMeters = $meters;
            }
        }

        return $best;
    }

    /**
     * Nombre del lugar activo más cercano que contiene el punto, o null.
     *
     * @param Collection<int, Place> $places
     */
    public function placeNameAt(Collection $places, float $lat, float $lng): ?string
    {
        return $this->placeAt($places, $lat, $lng)?->name;
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
     * El viaje inmediatamente anterior y el siguiente (misma unidad) de cada viaje.
     *
     * @param Collection<int, Trip> $trips
     * @return array{0: Collection<int, Trip>, 1: Collection<int, Trip>} por id del viaje
     */
    protected function neighbours(Collection $trips): array
    {
        // Todos los viajes de esas unidades desde un día antes del primero hasta un día después del último
        $all = Trip::whereIn('vehicle_id', $trips->pluck('vehicle_id')->unique()->values())
            ->where('data_source', 'real')
            ->where('started_at', '>=', $trips->min('started_at')->copy()->subDay())
            ->where('started_at', '<=', $trips->max('started_at')->copy()->addDay())
            ->orderBy('vehicle_id')
            ->orderBy('started_at')
            ->get(['id', 'vehicle_id', 'started_at', 'ended_at']);

        $previous = collect();
        $next = collect();
        $last = [];

        foreach ($all as $trip) {
            if (isset($last[$trip->vehicle_id])) {
                $previous->put($trip->id, $last[$trip->vehicle_id]);
                $next->put($last[$trip->vehicle_id]->id, $trip);
            }
            $last[$trip->vehicle_id] = $trip;
        }

        return [$previous, $next];
    }

    /**
     * El lugar donde más tiempo estuvo la unidad entre dos viajes, o null si no pisó ninguno
     * o el hueco es muy largo. Por tiempo y no por el primero que pisó: 27 oct, al entrar al
     * lobby de Coral Beach la unidad pasó unos segundos por el radio del Krystal.
     *
     * @param Collection<int, Place> $places
     */
    protected function placeInGap(Collection $places, int $vehicleId, \DateTimeInterface $from, \DateTimeInterface $to, bool $lastHourOnly = false): ?array
    {
        if ($to->getTimestamp() - $from->getTimestamp() > self::GAP_MAX_SECONDS) {
            if (!$lastHourOnly) {
                return null;
            }
            // Para el origen basta la última hora antes de arrancar
            $from = (new \DateTimeImmutable('@' . ($to->getTimestamp() - self::GAP_MAX_SECONDS)))->setTimezone($to->getTimezone());
        }

        $positions = Position::where('vehicle_id', $vehicleId)
            ->where('valid', true)
            ->where('device_time', '>=', $from)
            ->where('device_time', '<=', $to)
            ->orderBy('device_time')
            ->get(['device_time', 'latitude', 'longitude']);

        $seconds = [];
        $points = [];
        foreach ($positions as $i => $position) {
            $point = $this->point($places, $position);
            $nextTime = $positions[$i + 1]->device_time ?? $to;
            if ($point['name'] === null) {
                continue;
            }
            $seconds[$point['place_id']] = ($seconds[$point['place_id']] ?? 0) + ($nextTime->getTimestamp() - $position->device_time->getTimestamp());
            $points[$point['place_id']] ??= $point;
        }

        if (!$seconds) {
            return null;
        }

        arsort($seconds);

        return $points[array_key_first($seconds)];
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