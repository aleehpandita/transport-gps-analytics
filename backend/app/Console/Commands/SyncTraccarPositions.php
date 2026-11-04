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
 *   php artisan traccar:sync-positions --hours=130 (se pide a Traccar en bloques de 6 h)
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
 * - Se rellena solo: cada unidad arranca desde su última posición guardada (menos una
 *   hora) si eso es antes que la ventana. Si el servidor o el cron se caen, la siguiente
 *   corrida trae todo lo que faltó, hasta MAX_BACKFILL_DAYS. 3 nov: el cron de producción
 *   no se instaló y quedaron 5,651 posiciones sin bajar; con esto no vuelve a pasar.
 *
 * - Por bloques de --chunk-hours (6 por defecto): pedir 130 h de golpe (2 nov) trajo
 *   ~15 mil posiciones en una sola respuesta y pasó de 128 MB.
 *
 * - Velocidad: Traccar entrega nudos; se convierte a km/h una sola vez, al guardar.
 * - ignition y motion vienen dentro de attributes, no en el primer nivel.
 */
class SyncTraccarPositions extends Command
{
    protected $signature = 'traccar:sync-positions
        {--hours=24 : Ventana de horas hacia atrás a consultar}
        {--chunk-hours=6 : Horas por consulta a Traccar}';

    protected $description = 'Sincroniza posiciones desde Traccar hacia la tabla positions, sin duplicados';

    private const KNOTS_TO_KMH = 1.852;
    private const MAX_BACKFILL_DAYS = 7;

    public function handle(TraccarService $traccar): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $to = CarbonImmutable::now('UTC');
        $window = $to->subHours($hours);
        $oldest = $to->subDays(self::MAX_BACKFILL_DAYS);

        $vehicles = Vehicle::where('active', true)->get();

        if ($vehicles->isEmpty()) {
            $this->warn('No hay vehículos activos registrados.');

            return self::SUCCESS;
        }

        $chunkHours = max(1, (int) $this->option('chunk-hours'));

        foreach ($vehicles as $vehicle) {
            $this->info("Sincronizando {$vehicle->name} (traccar_device_id: {$vehicle->traccar_device_id})...");

            // Desde la última posición guardada si hay un hueco más grande que la ventana
            $from = $window;
            $last = Position::where('vehicle_id', $vehicle->id)->where('data_source', 'real')->max('device_time');
            if ($last) {
                $resume = CarbonImmutable::parse($last, 'UTC')->subHour();
                if ($resume->lt($from)) {
                    $from = $resume->max($oldest);
                    $this->line('  Hueco desde ' . $resume->addHour()->toDateTimeString() . ' UTC: se rellena.');
                }
            }

            $totals = [0, 0, 0];

            for ($start = $from; $start < $to; $start = $start->addHours($chunkHours)) {
                $end = $start->addHours($chunkHours)->min($to);

                $positions = $traccar->getPositions(
                    $vehicle->traccar_device_id,
                    $start->toIso8601String(),
                    $end->toIso8601String()
                );

                if (empty($positions)) {
                    continue;
                }

                // Lo que ya existe para este vehículo en el bloque, en una sola consulta.
                // Una hora de margen: Traccar filtra por fixTime y aquí se guarda deviceTime.
                $existing = Position::where('vehicle_id', $vehicle->id)
                    ->where('data_source', 'real')
                    ->where('device_time', '>=', $start->subHour())
                    ->where('device_time', '<=', $end->addHour())
                    ->get(['traccar_position_id', 'device_time', 'latitude', 'longitude']);

                $knownIds = $existing->pluck('traccar_position_id')->flip()->all();

                $knownKeys = $existing
                    ->mapWithKeys(fn (Position $p) => [
                        $this->dedupKey($p->device_time, $p->latitude, $p->longitude) => true,
                    ])
                    ->all();

                $counts = DB::transaction(
                    fn () => $this->storePositions($vehicle, $positions, $knownIds, $knownKeys)
                );

                foreach ($counts as $i => $n) {
                    $totals[$i] += $n;
                }

                unset($positions, $existing, $knownIds, $knownKeys);
            }

            [$created, $sameId, $resent] = $totals;
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