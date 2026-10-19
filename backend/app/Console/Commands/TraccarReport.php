<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\TraccarService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Calidad de la telemetría por unidad, leída directo de Traccar.
 *
 *   php artisan traccar:report                  (todas las unidades activas, últimas 24 h)
 *   php artisan traccar:report --vehicle=2 --hours=48
 *
 * Lee de Traccar y no de la tabla positions porque Traccar conserva los reenvíos
 * del dispositivo, que el sync descarta. Mide lo mismo que las consultas del anexo
 * de docs/piloto-fmc920.md:
 * - reenvíos: copias de la misma posición (misma hora del GPS y coordenadas);
 * - retraso: serverTime - deviceTime de la primera copia que llegó;
 * - huecos entre posiciones por estado, ignorando los mayores a 2 h (noches, días sin uso).
 */
class TraccarReport extends Command
{
    protected $signature = 'traccar:report
        {--vehicle= : ID del vehículo en este sistema (por defecto, todos los activos)}
        {--hours=24 : Horas hacia atrás a analizar}';

    protected $description = 'Frecuencia, retraso de entrega y reenvíos por unidad, leídos de Traccar';

    private const MAX_GAP_SECONDS = 7200;

    public function handle(TraccarService $traccar): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $to = CarbonImmutable::now('UTC');
        $from = $to->subHours($hours);

        $vehicles = $this->option('vehicle')
            ? Vehicle::whereKey($this->option('vehicle'))->get()
            : Vehicle::where('active', true)->get();

        foreach ($vehicles as $vehicle) {
            $this->newLine();
            $this->info("{$vehicle->name}, últimas {$hours} h");

            $raw = collect($traccar->getPositions(
                $vehicle->traccar_device_id,
                $from->toIso8601String(),
                $to->toIso8601String()
            ));

            if ($raw->isEmpty()) {
                $this->warn('  Sin posiciones en el periodo.');

                continue;
            }

            $unique = $this->uniquePositions($raw);
            $delays = $unique->map(fn (array $p) => $p['server'] - $p['device'])->sort()->values();
            $resent = $raw->count() - $unique->count();

            $this->table(
                ['Recibidas', 'Únicas', 'Reenvíos', 'Retraso mediana', 'Retraso p90', 'Retraso máx', 'Última (Cancún)'],
                [[
                    $raw->count(),
                    $unique->count(),
                    $resent . ' (' . round(100 * $resent / $raw->count(), 1) . ' %)',
                    $this->duration($this->percentile($delays, 0.5)),
                    $this->duration($this->percentile($delays, 0.9)),
                    $this->duration($delays->last()),
                    CarbonImmutable::createFromTimestamp($unique->last()['device'], 'America/Cancun')->format('d M H:i'),
                ]]
            );

            $this->table(['Estado', 'Posiciones', 'Hueco promedio', 'Hueco máx'], $this->gapsByState($unique));
        }

        return self::SUCCESS;
    }

    /**
     * Una sola copia por posición física, con la hora de la primera llegada al servidor.
     * Ordenadas por hora del GPS.
     */
    private function uniquePositions(Collection $raw): Collection
    {
        return $raw
            ->map(fn (array $p) => [
                'key' => $p['deviceTime'] . '|' . $p['latitude'] . '|' . $p['longitude'],
                'device' => CarbonImmutable::parse($p['deviceTime'])->getTimestamp(),
                'server' => CarbonImmutable::parse($p['serverTime'])->getTimestamp(),
                'state' => $this->state($p),
            ])
            ->groupBy('key')
            ->map(fn (Collection $copies) => $copies->sortBy('server')->first())
            ->sortBy('device')
            ->values();
    }

    /**
     * Mismo criterio que el dashboard y las consultas del piloto.
     * Traccar entrega la velocidad en nudos; solo importa si es mayor que cero.
     */
    private function state(array $position): string
    {
        if (($position['speed'] ?? 0) > 0) {
            return 'en movimiento';
        }

        return ($position['attributes']['ignition'] ?? false) ? 'detenido, motor encendido' : 'apagado';
    }

    private function gapsByState(Collection $unique): array
    {
        $gaps = [];
        $previous = null;

        foreach ($unique as $position) {
            if ($previous !== null) {
                $gap = $position['device'] - $previous['device'];

                if ($gap < self::MAX_GAP_SECONDS) {
                    $gaps[$position['state']][] = $gap;
                }
            }

            $previous = $position;
        }

        $rows = [];

        foreach (['en movimiento', 'detenido, motor encendido', 'apagado'] as $state) {
            $values = collect($gaps[$state] ?? []);

            $rows[] = [
                $state,
                $values->count(),
                $values->isEmpty() ? '—' : $this->duration($values->avg()),
                $values->isEmpty() ? '—' : $this->duration($values->max()),
            ];
        }

        return $rows;
    }

    private function percentile(Collection $sorted, float $p): float
    {
        return $sorted->get((int) floor(($sorted->count() - 1) * $p), 0);
    }

    private function duration(float|int|null $seconds): string
    {
        $seconds = (float) ($seconds ?? 0);

        return match (true) {
            $seconds < 120 => round($seconds) . ' s',
            $seconds < 7200 => round($seconds / 60, 1) . ' min',
            default => round($seconds / 3600, 1) . ' h',
        };
    }
}