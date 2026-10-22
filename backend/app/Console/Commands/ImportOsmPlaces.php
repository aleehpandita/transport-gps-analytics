<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\ZoneMatchingService;
use Illuminate\Console\Command;

/**
 * Importa hoteles de OpenStreetMap como lugares candidatos.
 *
 *   php artisan places:import-osm docs/data/osm_hoteles.json
 *
 * El archivo es la respuesta JSON de Overpass (ver docs/data/README o el comando curl
 * del 21 oct 2026). Datos © colaboradores de OpenStreetMap, licencia ODbL.
 *
 * Los hoteles entran INACTIVOS: OpenStreetMap marca el centro del hotel, no el lobby, y en
 * la zona hotelera un radio alrededor de cada centro cubriría el bulevar Kukulcán completo.
 * Sirven para ponerle nombre a los lugares que aprende DBSCAN y para activarlos a mano.
 *
 * Es seguro correrlo varias veces: actualiza por id de OSM y nunca reactiva ni desactiva
 * un lugar que ya existía.
 */
class ImportOsmPlaces extends Command
{
    protected $signature = 'places:import-osm
        {file=../docs/data/osm_hoteles.json : Ruta al JSON de Overpass}
        {--radius=150 : Radio en metros de los candidatos}
        {--skip-near=150 : No importar si hay un lugar manual o aprendido a menos de estos metros}';

    protected $description = 'Importa hoteles de OpenStreetMap (Overpass JSON) como lugares candidatos';

    public function handle(ZoneMatchingService $zones): int
    {
        $path = $this->argument('file');

        if (!is_file($path)) {
            $this->error("No existe el archivo: {$path}");
            return self::FAILURE;
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (!is_array($json) || !isset($json['elements']) || !is_array($json['elements'])) {
            $this->error('El archivo no es una respuesta JSON de Overpass (falta "elements").');
            return self::FAILURE;
        }

        $radius = (int) $this->option('radius');
        $skipNearKm = (int) $this->option('skip-near') / 1000;

        // Lugares comprobados con datos reales: si un hotel de OSM cae junto a uno, gana el comprobado
        $verified = Place::whereIn('source', [Place::SOURCE_MANUAL, Place::SOURCE_LEARNED])
            ->get(['latitude', 'longitude']);

        $counts = ['creados' => 0, 'actualizados' => 0, 'junto a uno comprobado' => 0, 'sin nombre o sin coordenadas' => 0];

        $bar = $this->output->createProgressBar(count($json['elements']));

        foreach ($json['elements'] as $element) {
            $bar->advance();

            $name = trim($element['tags']['name'] ?? '');
            // Los nodos traen lat/lon; las áreas (way, relation) traen su centro en "center"
            $latitude = $element['lat'] ?? $element['center']['lat'] ?? null;
            $longitude = $element['lon'] ?? $element['center']['lon'] ?? null;

            if ($name === '' || $latitude === null || $longitude === null) {
                $counts['sin nombre o sin coordenadas']++;
                continue;
            }

            $nearVerified = $verified->contains(
                fn (Place $p) => $this->distanceKm($latitude, $longitude, $p->latitude, $p->longitude) <= $skipNearKm
            );

            if ($nearVerified) {
                $counts['junto a uno comprobado']++;
                continue;
            }

            $externalId = "{$element['type']}/{$element['id']}";

            $place = Place::firstOrNew(['source' => Place::SOURCE_OSM, 'external_id' => $externalId]);
            $isNew = !$place->exists;

            $place->fill([
                'name' => mb_substr($name, 0, 255),
                'type' => 'hotel',
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_m' => $radius,
                'zone_id' => $zones->resolve($latitude, $longitude)?->id,
                'notes' => 'OpenStreetMap; centro del hotel, no el lobby',
            ]);

            if ($isNew) {
                $place->is_active = false;
            }

            $place->save();

            $counts[$isNew ? 'creados' : 'actualizados']++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Resultado', 'Hoteles'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values());
        $this->line('Los nuevos quedan inactivos: el Trip Builder no los usa hasta activarlos.');

        return self::SUCCESS;
    }

    protected function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}