<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\Vehicle;
use App\Services\TraccarService;
use Illuminate\Console\Command;



/**
 * Comando para sincronizar posiciones desde Traccar hacia la tabla positions.
 * 
 * Se ejecuta con:
 * php artisan traccar:sync-positions --hours=24
 * 
 * La opción --hours indica la ventana de tiempo hacia atrás a consultar en Traccar (por defecto 24 horas).
 */
/** 
 * Puntos clave de este comando, conforme a las decisiones que ya tomamos:
 * 
 * Idempotencia real: antes de insertar, verifica si traccar_position_id ya existe — así puedes correr el comando cuantas veces quieras sin duplicar nada, tal como pedía el diseño original.
 * 
 *  Conversión de velocidad: aquí es donde se aplica la conversión de knots a km/h que detectamos ayer (* 1.852) — se hace una sola vez, al ingerir, nunca se guarda el valor crudo de Traccar. 
 * 
 * Extracción de ignition/motion: se toman de dentro de attributes, confirmando el hallazgo de ayer. 
 * 
 * --hours configurable: por default trae las últimas 24 horas, pero puedes ajustarlo (--hours=72 para probar con más rango).
 * 
 * Recorre todos los vehículos activos, no solo el Tiguan — ya queda listo para cuando agreguen más unidades a la flotilla.
 *
 */
class SyncTraccarPositions extends Command
{
    protected $signature = 'traccar:sync-positions {--hours=24 : Ventana de horas hacia atrás a consultar}';
    protected $description = 'Sincroniza posiciones nuevas desde Traccar hacia la tabla positions, evitando duplicados';

    public function handle(TraccarService $traccar): int
    {
        $hours = (int) $this->option('hours');
        $vehicles = Vehicle::where('active', true)->get();

        if ($vehicles->isEmpty()) {
            $this->warn('No hay vehículos activos registrados.');
            return self::SUCCESS;
        }

        foreach ($vehicles as $vehicle) {
            $this->info("Sincronizando {$vehicle->name} (traccar_device_id: {$vehicle->traccar_device_id})...");

            $positions = $traccar->getPositions(
                $vehicle->traccar_device_id,
                now()->subHours($hours)->toIso8601String(),
                now()->toIso8601String()
            );

            $created = 0;
            $skipped = 0;

            foreach ($positions as $raw) {
                $exists = Position::where('traccar_position_id', $raw['id'])->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                Position::create([
                    'vehicle_id' => $vehicle->id,
                    'traccar_position_id' => $raw['id'],
                    'latitude' => $raw['latitude'],
                    'longitude' => $raw['longitude'],
                    'altitude' => $raw['altitude'] ?? null,
                    'speed' => ($raw['speed'] ?? 0) * 1.852, // knots -> km/h
                    'course' => $raw['course'] ?? null,
                    'accuracy' => $raw['accuracy'] ?? null,
                    'ignition' => $raw['attributes']['ignition'] ?? null,
                    'motion' => $raw['attributes']['motion'] ?? null,
                    'valid' => $raw['valid'] ?? true,
                    'device_time' => $raw['deviceTime'],
                    'server_time' => $raw['serverTime'] ?? null,
                    'attributes' => $raw['attributes'] ?? [],
                    'data_source' => 'real',
                ]);

                $created++;
            }

            $this->info("  → {$created} nuevas, {$skipped} ya existentes (omitidas).");
        }

        return self::SUCCESS;
    }
}