<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\Vehicle;
use App\Services\TraccarService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza posiciones desde Traccar hacia la tabla positions.
 *
 *   php artisan traccar:sync-positions             (últimas 24 horas)
 *   php artisan traccar:sync-positions --hours=72
 *
 * Decisiones (detalle en docs/piloto-fmc920.md):
 *
 * - Ventana hacia atrás por hora del GPS. Traccar filtra from/to por fixTime, no por
 *   la hora en que la posición llegó al servidor. Como las posiciones pueden llegar con
 *   horas de retraso (hasta 8.4 h medido en el piloto), la ventana debe cubrir ese
 *   retraso. 24 h por defecto.
 *
 * - Doble deduplicación:
 *   1. Por traccar_position_id: la misma posición consultada en dos corridas.
 *   2. Por vehículo, device_time y coordenadas: el FMC920 reenvía registros cuando no
 *      recibe confirmación, y Traccar guarda cada reenvío con un id distinto
 *      (13 % de duplicados en el piloto).
 *
 * - Velocidad: Traccar entrega nudos; se convierte a km/h una sola vez, al guardar.
 * - ignition y motion vienen dentro de attributes, no en el primer nivel.
 */
class SyncTraccarPositions extends Command
{
    protected $signature = 'traccar:sync-positions {--hours=24 : Ventana de horas hacia atrás a consultar}';

    protected $description = 'Sincroniza posiciones desde Traccar hacia la tabla positions, sin duplicados';

    private const KNOTS_TO_KMH = 1.852;

    public function handle(TraccarService $traccar): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $to = CarbonImmutable::now('UTC');
        $from = $to->subHours($hours);

        $vehicles = Vehicle::where('active', true)->get();

        if ($vehicles->isEmpty()) {
            $this->warn('No hay vehículos activos registrados.');

            return self::SUCCESS;
        }

        foreach ($vehicles as $vehicle) {
            $this->info("Sincronizando {$vehicle->name} (traccar_device_id: {$vehicle->traccar_device_id})...");

            $positions = $traccar->getPositions(
                $vehicle->traccar_device_id,
                $from->toIso8601String(),
                $to->toIso8601String()
            );

            // Lo que ya existe para este vehículo en la ventana, en una sola consulta.
            // Una hora de margen: Traccar filtra por fixTime y aquí se guarda deviceTime.
            $existing = Position::where('vehicle_id', $vehicle->id)
                ->where('data_source', 'real')
                ->where('device_time', '>=', $from->subHour())
                ->get(['traccar_position_id', 'device_time', 'latitude', 'longitude']);

            $knownIds = $existing->pluck('traccar_position_id')->flip()->all();

            $knownKeys = $existing
                ->mapWithKeys(fn (Position $p) => [
                    $this->dedupKey($p->device_time, $p->latitude, $p->longitude) => true,
                ])
                ->all();

            [$created, $sameId, $resent] = DB::transaction(
                fn () => $this->storePositions($vehicle, $positions, $knownIds, $knownKeys)
            );

            $this->info("  → {$created} nuevas, {$sameId} ya existentes, {$resent} reenvíos descartados.");
        }

        return self::SUCCESS;
    }

    /**
     * Guarda las posiciones nuevas y regresa [creadas, ya existentes, reenvíos].
     */
    private function storePositions(Vehicle $vehicle, array $positions, array $knownIds, array $knownKeys): array
    {
        $created = 0;
        $sameId = 0;
        $resent = 0;

        foreach ($positions as $raw) {
            if (isset($knownIds[$raw['id']])) {
                $sameId++;

                continue;
            }

            $key = $this->dedupKey($raw['deviceTime'], $raw['latitude'], $raw['longitude']);

            if (isset($knownKeys[$key])) {
                $resent++;

                continue;
            }

            Position::create([
                'vehicle_id' => $vehicle->id,
                'traccar_position_id' => $raw['id'],
                'latitude' => $raw['latitude'],
                'longitude' => $raw['longitude'],
                'altitude' => $raw['altitude'] ?? null,
                'speed' => ($raw['speed'] ?? 0) * self::KNOTS_TO_KMH,
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

            // Para detectar duplicados dentro de la misma respuesta de Traccar
            $knownIds[$raw['id']] = true;
            $knownKeys[$key] = true;
            $created++;
        }

        return [$created, $sameId, $resent];
    }

    /**
     * Identifica una posición física: misma hora del GPS (en UTC) y mismas coordenadas.
     */
    private function dedupKey(\DateTimeInterface|string $deviceTime, float|string $latitude, float|string $longitude): string
    {
        $time = $deviceTime instanceof \DateTimeInterface
            ? CarbonImmutable::instance($deviceTime)
            : CarbonImmutable::parse($deviceTime);

        return sprintf(
            '%s|%.7f|%.7f',
            $time->utc()->format('Y-m-d H:i:s'),
            (float) $latitude,
            (float) $longitude
        );
    }
}