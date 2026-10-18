<?php

namespace App\Console\Commands;

use App\Models\Zone;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Importa polígonos GeoJSON a la tabla zones.
 *
 *   php artisan zones:import-geometry                  (lee docs/geojson/*.geojson)
 *   php artisan zones:import-geometry ../otra/carpeta
 *
 * Cada archivo puede ser:
 * - un FeatureCollection con varias zonas: cada Feature lleva properties.name igual
 *   al nombre de la zona en la tabla (por ejemplo "Playa Mujeres");
 * - un Feature o una geometría sueltos: la zona sale del nombre del archivo
 *   (por ejemplo playa-mujeres.geojson).
 *
 * Solo acepta Polygon y MultiPolygon. Al importar, geometry_type cambia a ese tipo y
 * ZoneMatchingService usa el polígono en lugar del punto con radio.
 * Si se vuelve a correr ZonesSeeder (desde docs/zones.csv), hay que volver a importar.
 */
class ImportZoneGeometry extends Command
{
    protected $signature = 'zones:import-geometry {path=../docs/geojson : Carpeta con archivos .geojson, relativa a backend/}';

    protected $description = 'Importa polígonos GeoJSON a la tabla zones, emparejando por nombre de zona';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        $files = glob("{$path}/*.geojson");

        if (empty($files)) {
            $this->warn("No se encontraron archivos .geojson en {$path}");

            return self::SUCCESS;
        }

        $zones = Zone::all();
        $updated = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!is_array($data)) {
                $this->error('JSON inválido: ' . basename($file));
                $skipped++;

                continue;
            }

            foreach ($this->extractFeatures($data, $file) as [$name, $geometry]) {
                $zone = $this->findZone($zones, $name);

                if (!$zone) {
                    $this->warn("Sin zona con nombre \"{$name}\" en " . basename($file));
                    $skipped++;

                    continue;
                }

                $type = $geometry['type'] ?? null;

                if (!in_array($type, ['Polygon', 'MultiPolygon'], true)) {
                    $this->warn("{$zone->name}: tipo de geometría no soportado (" . ($type ?? 'vacío') . ')');
                    $skipped++;

                    continue;
                }

                $zone->update([
                    'geometry_type' => $type,
                    'geometry' => $geometry,
                ]);

                $this->info("Actualizada: {$zone->name} ({$type})");
                $updated++;
            }
        }

        $this->info("  → {$updated} zonas actualizadas, {$skipped} omitidas.");

        return self::SUCCESS;
    }

    /**
     * Regresa una lista de [nombre de zona, geometría] según la forma del GeoJSON.
     *
     * @return array<int, array{0: string, 1: array}>
     */
    private function extractFeatures(array $data, string $file): array
    {
        $nameFromFile = pathinfo($file, PATHINFO_FILENAME);

        return match ($data['type'] ?? null) {
            'FeatureCollection' => array_map(
                fn (array $feature) => [$feature['properties']['name'] ?? $nameFromFile, $feature['geometry'] ?? []],
                $data['features'] ?? []
            ),
            'Feature' => [[$data['properties']['name'] ?? $nameFromFile, $data['geometry'] ?? []]],
            default => [[$nameFromFile, $data]],
        };
    }

    /**
     * Empareja sin importar mayúsculas ni guiones: "playa-mujeres" = "Playa Mujeres".
     */
    private function findZone(Collection $zones, string $name): ?Zone
    {
        $normalize = fn (string $value) => mb_strtolower(trim(str_replace('-', ' ', $value)));

        return $zones->first(fn (Zone $zone) => $normalize($zone->name) === $normalize($name));
    }
}