<?php

namespace App\Console\Commands;

use App\Models\TrafficSignal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa los semáforos de OpenStreetMap (respuesta JSON de Overpass).
 *
 *   php artisan signals:import-osm
 *
 * Reemplaza todos los semáforos por los del archivo: es una foto de OSM, no algo que se
 * edite a mano. Datos © colaboradores de OpenStreetMap, licencia ODbL (docs/data/README.md).
 */
class ImportTrafficSignals extends Command
{
    protected $signature = 'signals:import-osm {file=../docs/data/osm_semaforos.json : Ruta al JSON de Overpass}';

    protected $description = 'Importa los semáforos de OpenStreetMap';

    public function handle(): int
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

        $now = now();
        $rows = collect($json['elements'])
            ->filter(fn ($e) => ($e['type'] ?? null) === 'node' && isset($e['lat'], $e['lon']))
            ->map(fn ($e) => [
                'osm_id' => "node/{$e['id']}",
                'latitude' => $e['lat'],
                'longitude' => $e['lon'],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->unique('osm_id')
            ->values();

        DB::transaction(function () use ($rows) {
            TrafficSignal::query()->delete();

            // En bloques: SQLite tiene un límite de variables por consulta
            foreach ($rows->chunk(150) as $chunk) {
                TrafficSignal::insert($chunk->all());
            }
        });

        $this->info("Semáforos importados: {$rows->count()}");

        return self::SUCCESS;
    }
}