<?php

namespace App\Console\Commands;

use App\Models\Zone;
use Illuminate\Console\Command;

class ImportZoneGeometry extends Command
{
    protected $signature = 'zones:import-geometry {path=../docs/geojson}';
    protected $description = 'Importa geometrías GeoJSON (una por zona) a la tabla zones, matcheando por nombre de archivo';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        $files = glob("{$path}/*.geojson");

        if (empty($files)) {
            $this->warn("No se encontraron archivos .geojson en {$path}");
            return self::SUCCESS;
        }

        foreach ($files as $file) {
            $zoneName = pathinfo($file, PATHINFO_FILENAME);
            $zone = Zone::whereRaw('LOWER(REPLACE(name, " ", "-")) = ?', [strtolower($zoneName)])->first();

            if (!$zone) {
                $this->warn("Sin zona correspondiente para el archivo: {$file}");
                continue;
            }

            $geoJson = json_decode(file_get_contents($file), true);
            $feature = $geoJson['features'][0] ?? $geoJson;

            $zone->update([
                'geometry_type' => $feature['geometry']['type'] ?? $feature['type'],
                'geometry' => $feature['geometry'] ?? $feature,
            ]);

            $this->info("Actualizada: {$zone->name}");
        }

        return self::SUCCESS;
    }
}