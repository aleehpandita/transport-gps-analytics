<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\TraccarService;
use Illuminate\Console\Command;

/**
 * Da de alta en vehicles los dispositivos registrados en Traccar.
 *
 *   php artisan traccar:sync-devices
 *
 * - Empareja por traccar_device_id.
 * - Si el dispositivo no existe, crea el vehículo con el nombre de Traccar y su IMEI,
 *   activo salvo que esté deshabilitado en Traccar.
 * - Si ya existe, solo completa el IMEI si faltaba. No toca nombre, placas, marca,
 *   modelo ni active, que pueden haberse editado en este sistema.
 *
 * Flujo para una unidad nueva: registrarla en Traccar (web, con su IMEI), correr
 * este comando y después traccar:sync-positions.
 */
class SyncTraccarDevices extends Command
{
    protected $signature = 'traccar:sync-devices';

    protected $description = 'Crea en vehicles los dispositivos de Traccar que todavía no existen';

    public function handle(TraccarService $traccar): int
    {
        $devices = $traccar->getDevices();

        if (empty($devices)) {
            $this->warn('Traccar no regresó dispositivos.');

            return self::SUCCESS;
        }

        $created = 0;
        $existing = 0;

        foreach ($devices as $device) {
            $vehicle = Vehicle::firstWhere('traccar_device_id', $device['id']);

            if ($vehicle) {
                if (empty($vehicle->imei) && !empty($device['uniqueId'])) {
                    $vehicle->update(['imei' => $device['uniqueId']]);
                }

                $this->line("  Ya existe: {$vehicle->name} (traccar_device_id: {$device['id']})");
                $existing++;

                continue;
            }

            $vehicle = Vehicle::create([
                'name' => $device['name'],
                'imei' => $device['uniqueId'] ?? null,
                'traccar_device_id' => $device['id'],
                'active' => !($device['disabled'] ?? false),
            ]);

            $this->info("  Nueva: {$vehicle->name} (traccar_device_id: {$device['id']})");
            $created++;
        }

        $this->info("  → {$created} nuevas, {$existing} ya existentes.");

        return self::SUCCESS;
    }
}