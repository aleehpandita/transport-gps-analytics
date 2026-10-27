<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Trip;
use App\Models\TripReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * La operación de un día armada solo con GPS: servicios de llegada y salida con su hotel,
 * terminal y horarios, km con pasajeros contra vacíos, y los puntos donde se subió o bajó
 * gente que todavía no tienen nombre (con el hotel de OpenStreetMap más cercano como
 * sugerencia, para darlo de alta con un clic).
 *
 * Lo que una persona confirmó (trip_reviews) manda sobre lo que supone el GPS.
 */
class OperationService
{
    protected const TIMEZONE = 'America/Cancun';
    // Hotel de OpenStreetMap que se sugiere para un punto sin nombre
    protected const SUGGESTION_METERS = 150;
    // Puntos sin nombre a menos de esto se juntan en una sola sugerencia
    protected const SAME_SPOT_METERS = 60;

    protected ?Collection $osm = null;

    /** @var list<array{lat: float, lng: float, name: string}> */
    protected array $terminals = [];

    public function __construct(protected TripPlaceResolver $resolver)
    {
    }

    /**
     * @param Collection<int, string> $vehicles Nombre por id, ya filtradas por usuario
     */
    public function forDay(string $date, Collection $vehicles): array
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d', $date, self::TIMEZONE)->startOfDay();
        $to = $from->endOfDay();

        $trips = Trip::whereIn('vehicle_id', $vehicles->keys())
            ->where('data_source', 'real')
            ->whereBetween('started_at', [$from->utc(), $to->utc()])
            ->orderBy('vehicle_id')
            ->orderBy('started_at')
            ->get(['id', 'vehicle_id', 'started_at', 'ended_at', 'distance_km']);

        $ends = $this->resolver->ends($trips);
        $this->terminals = $this->resolver->places()
            ->where('type', 'airport_terminal')
            ->map(fn (Place $p) => ['lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'name' => $p->name])
            ->values()
            ->all();
        $classifier = new OperationClassifier($this->terminals);

        // Confirmaciones de ese día, por "unidad|inicio del viaje"
        $reviews = TripReview::with('user:id,name')
            ->whereIn('vehicle_id', $vehicles->keys())
            ->whereBetween('trip_started_at', [$from->utc(), $to->utc()])
            ->get()
            ->keyBy(fn (TripReview $r) => $r->vehicle_id . '|' . $r->trip_started_at->getTimestamp());

        $legs = [];
        foreach ($trips->groupBy('vehicle_id') as $vehicleId => $vehicleTrips) {
            $input = $vehicleTrips->filter(fn (Trip $t) => isset($ends[$t->id]['origin']['lat'], $ends[$t->id]['destination']['lat']))
                ->map(fn (Trip $t) => [
                    'id' => $t->id,
                    'vehicle_id' => $vehicleId,
                    'start' => $t->started_at->getTimestamp(),
                    'end' => $t->ended_at->getTimestamp(),
                    'km' => (float) $t->distance_km,
                    'origin' => $ends[$t->id]['origin'],
                    'destination' => $ends[$t->id]['destination'],
                    'review' => ($r = $reviews->get($vehicleId . '|' . $t->started_at->getTimestamp())) ? [
                        'status' => $r->status,
                        'note' => $r->note,
                        'by' => $r->user?->name,
                        'at' => $r->updated_at->toIso8601String(),
                    ] : null,
                ])
                ->values()
                ->all();

            array_push($legs, ...$classifier->classify($input));
        }

        // Servicios: los que detectó el GPS y los viajes que alguien marcó como servicio
        $services = collect($legs)
            ->filter(fn ($l) => isset($l['service']) || ($l['review']['status'] ?? null) === OperationClassifier::REVIEW_SERVICE)
            ->values();

        return [
            'date' => $date,
            'summary' => $this->summary($legs, $vehicles),
            'services' => $services->map(fn ($l) => $this->serviceRow($l, $vehicles))->values(),
            'legs' => collect($legs)->map(fn ($l) => $this->legRow($l, $vehicles))->values(),
            'unnamed' => $this->unnamed($services->filter(fn ($l) => isset($l['service']))),
        ];
    }

    protected function summary(array $legs, Collection $vehicles): array
    {
        $legs = collect($legs);
        $services = $legs->filter(fn ($l) => $l['status'] !== null);

        return [
            'arrivals' => $legs->where('kind', OperationClassifier::ARRIVAL)->count(),
            'departures' => $legs->where('kind', OperationClassifier::DEPARTURE)->count(),
            'pending' => $services->where('status', OperationClassifier::PENDING)->count(),
            'confirmed' => $services->where('status', OperationClassifier::CONFIRMED)->count(),
            'km_with_passengers' => round($legs->where('with_passengers', true)->sum('km'), 1),
            // Dudosos: no cuentan como km con pasajeros hasta que alguien los confirme
            'km_pending' => round($legs->where('status', OperationClassifier::PENDING)->sum('km'), 1),
            'km_empty' => round($legs->where('with_passengers', false)->where('status', '!==', OperationClassifier::PENDING)->sum('km'), 1),
            'vehicles' => $legs->pluck('vehicle_id')->unique()->count(),
            'first_start' => $legs->min('start') ? $this->iso($legs->min('start')) : null,
            'last_end' => $legs->max('end') ? $this->iso($legs->max('end')) : null,
        ];
    }

    protected function serviceRow(array $leg, Collection $vehicles): array
    {
        // Un viaje que el GPS no tomó como servicio pero alguien marcó como tal
        $s = $leg['service'] ?? [
            'type' => 'otro',
            'hotel' => $leg['destination']['name'],
            'hotel_point' => [$leg['destination']['lat'], $leg['destination']['lng']],
            'terminal' => null,
            'left_at' => $leg['start'],
            'arrived_at' => $leg['end'],
        ];

        $terminal = $s['type'] === OperationClassifier::ARRIVAL ? $leg['origin'] : $leg['destination'];

        return [
            'trip_id' => $leg['id'],
            'vehicle_id' => $leg['vehicle_id'],
            'vehicle' => $vehicles[$leg['vehicle_id']],
            'type' => $s['type'],
            'status' => $leg['status'] ?? OperationClassifier::CONFIRMED,
            'reasons' => $leg['reasons'],
            'review' => $leg['review'],
            'hotel' => $s['hotel'],
            'hotel_latitude' => $s['hotel_point'][0],
            'hotel_longitude' => $s['hotel_point'][1],
            'terminal' => $s['type'] === 'otro' ? null : $this->terminalName($terminal),
            // Llegada: reached_hotel_at es null. Salida: cuándo llegó por los pasajeros.
            'reached_hotel_at' => isset($s['reached_hotel_at']) ? $this->iso($s['reached_hotel_at']) : null,
            'left_at' => $this->iso($s['left_at']),
            'arrived_at' => $this->iso($s['arrived_at']),
            'wait_seconds' => $s['wait_seconds'] ?? null,
            'stay_seconds' => $s['stay_seconds'] ?? null,
            'km' => round($leg['km'], 1),
        ];
    }

    protected function legRow(array $leg, Collection $vehicles): array
    {
        return [
            'trip_id' => $leg['id'],
            'vehicle_id' => $leg['vehicle_id'],
            'vehicle' => $vehicles[$leg['vehicle_id']],
            'kind' => $leg['kind'],
            'status' => $leg['status'],
            'review' => $leg['review'],
            'with_passengers' => $leg['with_passengers'],
            'started_at' => $this->iso($leg['start']),
            'ended_at' => $this->iso($leg['end']),
            'km' => round($leg['km'], 1),
            'origin' => $leg['origin']['name'],
            'destination' => $leg['destination']['name'],
        ];
    }

    /**
     * Nombre de la terminal: si el punto es un lugar del aeropuerto que no es terminal (el
     * punto de encuentro, la espera de transportistas), la terminal más cercana.
     */
    protected function terminalName(array $point): ?string
    {
        if (($point['type'] ?? null) === 'airport_terminal' || $point['lat'] === null) {
            return $point['name'];
        }

        $best = null;
        $bestMeters = INF;
        foreach ($this->terminals as $t) {
            $meters = $this->meters($point['lat'], $point['lng'], $t['lat'], $t['lng']);
            if ($meters < $bestMeters) {
                $best = $t['name'];
                $bestMeters = $meters;
            }
        }

        return $best ?? $point['name'];
    }

    /**
     * Hoteles donde se subió o bajó gente y que no tienen nombre todavía, uno por punto,
     * con el hotel de OpenStreetMap más cercano como sugerencia.
     */
    protected function unnamed(Collection $services): Collection
    {
        $spots = [];

        foreach ($services->filter(fn ($l) => $l['service']['hotel'] === null) as $leg) {
            [$lat, $lng] = $leg['service']['hotel_point'];

            foreach ($spots as $k => $spot) {
                if ($this->meters($lat, $lng, $spot['latitude'], $spot['longitude']) <= self::SAME_SPOT_METERS) {
                    $spots[$k]['services']++;
                    continue 2;
                }
            }

            $spots[] = [
                'latitude' => $lat,
                'longitude' => $lng,
                'first_seen_at' => $this->iso($leg['service']['type'] === OperationClassifier::ARRIVAL ? $leg['end'] : $leg['start']),
                'services' => 1,
                'suggestion' => $this->suggestion($lat, $lng),
            ];
        }

        return collect($spots);
    }

    protected function suggestion(float $lat, float $lng): ?array
    {
        // Solo los candidatos de OpenStreetMap (inactivos); los activos ya habrían dado nombre
        $best = null;
        $bestMeters = INF;

        foreach ($this->osmHotels() as $hotel) {
            $meters = $this->meters($lat, $lng, (float) $hotel->latitude, (float) $hotel->longitude);
            if ($meters <= self::SUGGESTION_METERS && $meters < $bestMeters) {
                $best = $hotel;
                $bestMeters = $meters;
            }
        }

        return $best ? ['name' => $best->name, 'distance_m' => (int) round($bestMeters)] : null;
    }

    protected function osmHotels(): Collection
    {
        return $this->osm ??= Place::where('source', Place::SOURCE_OSM)
            ->where('is_active', false)
            ->get(['name', 'latitude', 'longitude']);
    }

    protected function iso(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601String();
    }

    protected function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($a)));
    }
}