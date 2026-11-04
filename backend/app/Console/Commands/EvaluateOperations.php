<?php

namespace App\Console\Commands;

use App\Models\ServiceOrder;
use App\Models\Vehicle;
use App\Services\OperationService;
use App\Services\OrderMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Mide qué tan bien detecta el GPS los servicios: compara las órdenes de servicio
 * (orders:import) contra lo que arma OperationService SIN las confirmaciones de nadie, o
 * sea, lo que el sistema hace solo.
 *
 *   php artisan operations:evaluate
 *   php artisan operations:evaluate --from=2026-10-27 --to=2026-11-02 --details
 *
 * Correr antes de subir cualquier cambio al Trip Builder o al clasificador: si un número
 * baja, el cambio rompió algo que ya funcionaba.
 */
class EvaluateOperations extends Command
{
    protected $signature = 'operations:evaluate
        {--from= : Primer día (AAAA-MM-DD); por omisión, el primero con órdenes}
        {--to= : Último día (AAAA-MM-DD)}
        {--vehicle= : Parte del nombre de la unidad}
        {--details : Muestra cada orden que no salió perfecta y cada servicio inventado}';

    protected $description = 'Compara las órdenes de servicio contra lo que detecta el GPS';

    protected const TIMEZONE = 'America/Cancun';

    protected const LABELS = [
        OrderMatcher::OK => 'correcto',
        OrderMatcher::UNNAMED => 'detectado, punto sin nombre',
        OrderMatcher::OTHER_PLACE => 'detectado en otro lugar',
        OrderMatcher::MISSED => 'NO DETECTADO',
    ];

    public function handle(OperationService $operations, OrderMatcher $matcher): int
    {
        $query = ServiceOrder::with('place:id,name,latitude,longitude')
            ->when($this->option('from'), fn ($q, $d) => $q->whereDate('service_date', '>=', $d))
            ->when($this->option('to'), fn ($q, $d) => $q->whereDate('service_date', '<=', $d))
            ->when($this->option('vehicle'), fn ($q, $v) => $q->whereIn(
                'vehicle_id', Vehicle::where('name', 'like', "%{$v}%")->pluck('id')
            ))
            ->orderBy('service_date')
            ->orderBy('scheduled_time');

        $orders = $query->get();
        if ($orders->isEmpty()) {
            $this->warn('No hay órdenes en ese periodo. Cárgalas con orders:import.');
            return self::SUCCESS;
        }

        $rows = [];
        $totals = array_fill_keys([...array_keys(self::LABELS), 'orders', 'extra', 'extra_probable', 'auto'], 0);

        // Todos los días del periodo, también los que no tienen órdenes: ahí cualquier servicio
        // que detecte el GPS es inventado (30 oct: el chofer usó la van sin permiso)
        $byDate = $orders->groupBy(fn (ServiceOrder $o) => $o->service_date->format('Y-m-d'));
        $vehicles = Vehicle::whereIn('id', $orders->pluck('vehicle_id')->unique())->pluck('name', 'id');
        $first = CarbonImmutable::parse($this->option('from') ?? $byDate->keys()->first());
        $last = CarbonImmutable::parse($this->option('to') ?? $byDate->keys()->last());

        for ($d = $first; $d->lte($last); $d = $d->addDay()) {
            $date = $d->format('Y-m-d');
            $dayOrders = $byDate->get($date, collect());
            $day = $operations->forDay($date, $vehicles, withReviews: false);

            $result = $matcher->match(
                $dayOrders->map(fn (ServiceOrder $o) => [
                    'id' => $o->id,
                    'vehicle_id' => $o->vehicle_id,
                    'time' => CarbonImmutable::parse("{$date} {$o->scheduled_time}", self::TIMEZONE)->getTimestamp(),
                    'type' => $o->type,
                    'place_id' => $o->place_id,
                    'place_text' => $o->place?->name ?? $o->place_text,
                    'place_lat' => $o->place ? (float) $o->place->latitude : null,
                    'place_lng' => $o->place ? (float) $o->place->longitude : null,
                    'scheduled_time' => $o->scheduled_time,
                ])->values()->all(),
                collect($day['services'])->map(fn ($s) => [
                    'trip_id' => $s['trip_id'],
                    'vehicle_id' => $s['vehicle_id'],
                    'type' => $s['type'],
                    'left_at' => CarbonImmutable::parse($s['left_at'])->getTimestamp(),
                    'place_id' => $s['place_id'],
                    'hotel' => $s['hotel'],
                    'hotel_latitude' => $s['hotel_latitude'],
                    'hotel_longitude' => $s['hotel_longitude'],
                    'status' => $s['status'],
                ])->values()->all()
            );

            $count = array_fill_keys(array_keys(self::LABELS), 0);
            $auto = 0;
            foreach ($result['orders'] as $o) {
                $count[$o['result']]++;
                // Lo que el sistema resolvió solo: correcto y sin nada que confirmar
                if ($o['result'] === OrderMatcher::OK && $o['service']['status'] === 'probable') {
                    $auto++;
                }
            }
            $extraProbable = collect($result['extra'])->where('status', 'probable')->count();

            $rows[] = [
                $date, count($result['orders']), $count[OrderMatcher::OK], $count[OrderMatcher::UNNAMED],
                $count[OrderMatcher::OTHER_PLACE], $count[OrderMatcher::MISSED], $auto,
                count($result['extra']) . ($extraProbable ? " ({$extraProbable} probables)" : ''),
            ];

            foreach ($count as $k => $v) {
                $totals[$k] += $v;
            }
            $totals['orders'] += count($result['orders']);
            $totals['extra'] += count($result['extra']);
            $totals['extra_probable'] += $extraProbable;
            $totals['auto'] += $auto;

            if ($this->option('details')) {
                $this->printDetails($date, $result);
            }
        }

        $this->newLine();
        $this->table(
            ['Día', 'Órdenes', 'Correctos', 'Sin nombre', 'Otro lugar', 'No detectados', 'Solos (sin confirmar)', 'Inventados'],
            $rows
        );

        $n = max(1, $totals['orders']);
        $detected = $totals[OrderMatcher::OK] + $totals[OrderMatcher::UNNAMED] + $totals[OrderMatcher::OTHER_PLACE];
        $this->line(sprintf('Detectados:            %3d de %d (%.0f%%)', $detected, $totals['orders'], 100 * $detected / $n));
        $this->line(sprintf('Correctos con lugar:   %3d de %d (%.0f%%)', $totals[OrderMatcher::OK], $totals['orders'], 100 * $totals[OrderMatcher::OK] / $n));
        $this->line(sprintf('Resueltos solos:       %3d de %d (%.0f%%)  correctos y sin nada que confirmar', $totals['auto'], $totals['orders'], 100 * $totals['auto'] / $n));
        $this->line(sprintf('Inventados:            %3d (%d probables, %d por confirmar)', $totals['extra'], $totals['extra_probable'], $totals['extra'] - $totals['extra_probable']));

        return self::SUCCESS;
    }

    protected function printDetails(string $date, array $result): void
    {
        $lines = [];
        foreach ($result['orders'] as $o) {
            if ($o['result'] === OrderMatcher::OK && $o['service']['status'] === 'probable') {
                continue;
            }
            $s = $o['service'];
            $lines[] = sprintf(
                '  %s %-8s %-30s %s%s',
                $o['scheduled_time'], $o['type'], mb_strimwidth($o['place_text'], 0, 30, '…'),
                self::LABELS[$o['result']],
                $s ? sprintf(' (GPS: %s, %s, %+d min, %s)', $this->hm($s['left_at']), $s['hotel'] ?? 'sin nombre', $o['minutes'], $s['status']) : ''
            );
        }
        foreach ($result['extra'] as $s) {
            $lines[] = sprintf('  %s %-8s %-30s inventado (%s)', $this->hm($s['left_at']), $s['type'], mb_strimwidth($s['hotel'] ?? 'sin nombre', 0, 30, '…'), $s['status']);
        }

        if ($lines) {
            $this->line("<comment>{$date}</comment>");
            foreach ($lines as $line) {
                $this->line($line);
            }
        }
    }

    protected function hm(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestamp($timestamp, self::TIMEZONE)->format('H:i');
    }
}