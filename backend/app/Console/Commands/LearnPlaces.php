<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\SignalIndex;
use App\Services\ZoneMatchingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Encuentra lugares de servicio a partir de las paradas repetidas, con DBSCAN.
 *
 *   php artisan places:learn                  solo muestra lo que encontró
 *   php artisan places:learn --apply          pregunta uno por uno si darlos de alta
 *   php artisan places:learn --vehicle=2      solo con los viajes de una unidad
 *
 * Idea: las bajadas y recogidas se repiten en los mismos puntos (el lobby de Valentin,
 * la banqueta de la T3). Los puntos donde terminan los viajes, agrupados por cercanía,
 * marcan esos lugares con más precisión que cualquier mapa: el 20 oct, el punto de Google
 * Maps de la T3 estaba a 145 m de donde de verdad se detienen las camionetas.
 *
 * DBSCAN (Ester et al., 1996): un punto es "central" si tiene al menos min-visits vecinos
 * a menos de eps metros (contándose a sí mismo); los centrales cercanos y sus vecinos forman
 * un grupo; lo demás es ruido. No hace falta decir cuántos grupos buscar.
 */
class LearnPlaces extends Command
{
    protected $signature = 'places:learn
        {--vehicle=* : IDs de unidades (por defecto, todas)}
        {--eps=60 : Distancia máxima en metros entre paradas del mismo grupo}
        {--min-visits=3 : Visitas mínimas para formar un grupo}
        {--min-days=2 : Días distintos mínimos (descarta una sola espera larga)}
        {--apply : Preguntar uno por uno si dar de alta los lugares nuevos}';

    protected $description = 'Aprende lugares de servicio agrupando con DBSCAN los puntos donde terminan los viajes';

    // Un grupo cuyo centro cae a esta distancia de un lugar activo se considera el mismo lugar
    protected const KNOWN_EXTRA_METERS = 50;
    // Distancia máxima para sugerir el nombre de un hotel de OSM
    protected const OSM_NAME_METERS = 300;
    // Un final de viaje junto a un semáforo no cuenta: un cruce con tráfico se repite todos
    // los días a la misma hora y DBSCAN lo aprendería como lugar (pasó el 21 y 22 oct)
    protected const SIGNAL_METERS = 40;

    public function handle(ZoneMatchingService $zones, SignalIndex $signals): int
    {
        $epsKm = (int) $this->option('eps') / 1000;
        $minVisits = (int) $this->option('min-visits');
        $minDays = (int) $this->option('min-days');

        $all = $this->tripEndPoints();
        $points = array_values(array_filter(
            $all,
            fn (array $p) => !$signals->isNear($p['lat'], $p['lng'], self::SIGNAL_METERS)
        ));

        if (count($all) > count($points)) {
            $this->line((count($all) - count($points)) . ' finales de viaje junto a un semáforo no se toman en cuenta.');
        }

        if (count($points) < $minVisits) {
            $this->warn('Muy pocos viajes para aprender lugares (' . count($points) . ').');
            return self::SUCCESS;
        }

        $this->info(count($points) . ' finales de viaje; agrupando con eps ' . $this->option('eps') . " m y {$minVisits} visitas mínimas...");

        $labels = $this->dbscan($points, $epsKm, $minVisits);

        $active = Place::where('is_active', true)->get();
        $osm = Place::where('source', Place::SOURCE_OSM)->get();

        $clusters = collect($labels)
            ->filter(fn (int $label) => $label > 0)
            ->keys()
            ->groupBy(fn (int $i) => $labels[$i])
            ->map(fn ($indexes) => $this->summarize(array_map(fn ($i) => $points[$i], $indexes->all()), $active, $osm))
            ->filter(fn (array $c) => $c['days'] >= $minDays)
            ->sortByDesc('visits')
            ->values();

        if ($clusters->isEmpty()) {
            $this->warn('No hay paradas que se repitan lo suficiente todavía. Prueba con más días de datos.');
            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Visitas', 'Días', 'Centro', 'Radio', 'Sugerencia', 'Estado'],
            $clusters->map(fn (array $c, int $i) => [
                $i + 1,
                $c['visits'],
                $c['days'],
                sprintf('%.5f, %.5f', $c['latitude'], $c['longitude']),
                $c['radius_m'] . ' m',
                $c['suggested_name'],
                $c['known'] ? "ya existe: {$c['known']['name']} ({$c['known']['meters']} m)" : 'NUEVO',
            ])
        );

        if (!$this->option('apply')) {
            $this->line('Nada se guardó. Corre con --apply para dar de alta los nuevos.');
            return self::SUCCESS;
        }

        foreach ($clusters->where('known', null) as $c) {
            $this->newLine();
            $this->line(sprintf(
                '<info>%s</info>: %d visitas en %d días, centro %.5f, %.5f, radio %d m',
                $c['suggested_name'], $c['visits'], $c['days'], $c['latitude'], $c['longitude'], $c['radius_m']
            ));

            if (!$this->confirm('¿Darlo de alta?', true)) {
                continue;
            }

            $name = $this->ask('Nombre', $c['suggested_name']);

            Place::updateOrCreate(
                ['source' => Place::SOURCE_LEARNED, 'external_id' => sprintf('dbscan:%.5f,%.5f', $c['latitude'], $c['longitude'])],
                [
                    'name' => $name,
                    'type' => $c['suggested_type'],
                    'latitude' => $c['latitude'],
                    'longitude' => $c['longitude'],
                    'radius_m' => $c['radius_m'],
                    'zone_id' => $zones->resolve($c['latitude'], $c['longitude'])?->id,
                    'is_active' => true,
                    'notes' => sprintf('DBSCAN %s: %d visitas en %d días', now('America/Cancun')->toDateString(), $c['visits'], $c['days']),
                ]
            );

            $this->info("Dado de alta: {$name}");
        }

        return self::SUCCESS;
    }

    /**
     * Punto donde terminó cada viaje real: ahí la unidad se detuvo (3 min o más, o en un lugar).
     *
     * @return list<array{lat: float, lng: float, day: string}>
     */
    protected function tripEndPoints(): array
    {
        $query = DB::table('trips')
            ->join('positions', function ($join) {
                $join->on('positions.vehicle_id', '=', 'trips.vehicle_id')
                    ->on('positions.device_time', '=', 'trips.ended_at');
            })
            ->where('trips.data_source', 'real')
            ->select('positions.latitude', 'positions.longitude', 'trips.ended_at');

        $vehicleIds = array_filter(array_map('intval', (array) $this->option('vehicle')));

        if ($vehicleIds !== []) {
            $query->whereIn('trips.vehicle_id', $vehicleIds);
        }

        return $query->get()
            ->map(fn ($row) => [
                'lat' => (float) $row->latitude,
                'lng' => (float) $row->longitude,
                'day' => Carbon::parse($row->ended_at, 'UTC')->tz('America/Cancun')->toDateString(),
            ])
            ->all();
    }

    /**
     * DBSCAN. Regresa una etiqueta por punto: número de grupo (1, 2, ...) o -1 si es ruido.
     * O(n²): sobra para unos miles de finales de viaje.
     *
     * @param list<array{lat: float, lng: float, day: string}> $points
     * @return array<int, int>
     */
    protected function dbscan(array $points, float $epsKm, int $minPts): array
    {
        $labels = array_fill(0, count($points), 0); // 0 = sin visitar
        $cluster = 0;

        $neighbors = function (int $i) use ($points, $epsKm): array {
            $found = [];
            foreach ($points as $j => $p) {
                if ($this->distanceKm($points[$i]['lat'], $points[$i]['lng'], $p['lat'], $p['lng']) <= $epsKm) {
                    $found[] = $j;
                }
            }
            return $found;
        };

        foreach (array_keys($points) as $i) {
            if ($labels[$i] !== 0) {
                continue;
            }

            $seeds = $neighbors($i);

            if (count($seeds) < $minPts) {
                $labels[$i] = -1; // ruido, a menos que luego lo alcance un grupo
                continue;
            }

            $cluster++;
            $labels[$i] = $cluster;

            while ($seeds !== []) {
                $j = array_shift($seeds);

                if ($labels[$j] === -1) {
                    $labels[$j] = $cluster; // era ruido: queda como borde del grupo
                }

                if ($labels[$j] !== 0) {
                    continue;
                }

                $labels[$j] = $cluster;
                $more = $neighbors($j);

                if (count($more) >= $minPts) {
                    array_push($seeds, ...$more); // también es central: el grupo se extiende
                }
            }
        }

        return $labels;
    }

    /**
     * Centro, radio, días y nombre sugerido de un grupo.
     *
     * @param list<array{lat: float, lng: float, day: string}> $members
     */
    protected function summarize(array $members, $active, $osm): array
    {
        $lat = array_sum(array_column($members, 'lat')) / count($members);
        $lng = array_sum(array_column($members, 'lng')) / count($members);

        // Radio: el 90% de las visitas cae dentro, con 30 m de margen; entre 60 y 200 m
        $distances = array_map(fn ($m) => $this->distanceKm($lat, $lng, $m['lat'], $m['lng']) * 1000, $members);
        sort($distances);
        $p90 = $distances[(int) floor(0.9 * (count($distances) - 1))];
        $radius = (int) min(200, max(60, ceil($p90) + 30));

        $known = $this->nearest($active, $lat, $lng, fn (Place $p) => $p->radius_m + self::KNOWN_EXTRA_METERS);
        $hotel = $this->nearest($osm, $lat, $lng, fn () => self::OSM_NAME_METERS);

        return [
            'visits' => count($members),
            'days' => count(array_unique(array_column($members, 'day'))),
            'latitude' => round($lat, 6),
            'longitude' => round($lng, 6),
            'radius_m' => $radius,
            'known' => $known ? ['name' => $known['place']->name, 'meters' => $known['meters']] : null,
            'suggested_name' => $hotel ? $hotel['place']->name . ' lobby' : sprintf('Parada frecuente %.4f, %.4f', $lat, $lng),
            'suggested_type' => $hotel ? 'hotel' : 'other',
        ];
    }

    /**
     * Lugar más cercano dentro de su límite, o null.
     *
     * @return array{place: Place, meters: int}|null
     */
    protected function nearest($places, float $lat, float $lng, callable $limitMeters): ?array
    {
        $best = null;

        foreach ($places as $place) {
            $meters = $this->distanceKm($lat, $lng, $place->latitude, $place->longitude) * 1000;

            if ($meters <= $limitMeters($place) && ($best === null || $meters < $best['meters'])) {
                $best = ['place' => $place, 'meters' => (int) round($meters)];
            }
        }

        return $best;
    }

    protected function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}