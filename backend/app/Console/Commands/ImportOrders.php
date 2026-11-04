<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Models\ServiceOrder;
use App\Models\Vehicle;
use App\Services\PlaceNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Carga órdenes de servicio desde un CSV con encabezado:
 *
 *   fecha,hora,unidad,tipo,lugar,terminal,pax,notas
 *   2026-11-02,11:45,Kevs1,salida,Royal Solaris,T2,3,
 *
 * - unidad: parte del nombre de la unidad ("Kevs1")
 * - tipo: llegada, salida o traslado
 * - lugar: como viene en la orden; se busca en el catálogo y si no aparece se guarda el
 *   texto igual (el reporte lo marca para darlo de alta)
 * - Sin nombres de pasajeros.
 *
 * Reemplaza las órdenes de esa unidad y ese día que vinieron de un CSV, así que se puede
 * volver a cargar el mismo archivo después de corregirlo.
 */
class ImportOrders extends Command
{
    protected $signature = 'orders:import
        {file : Ruta del CSV}
        {--dry-run : Solo muestra cómo se leyó, sin guardar}';

    protected $description = 'Carga órdenes de servicio desde un CSV (sin nombres de pasajeros)';

    protected const COLUMNS = ['fecha', 'hora', 'unidad', 'tipo', 'lugar', 'terminal', 'pax', 'notas'];

    public function handle(PlaceNameMatcher $matcher): int
    {
        $path = $this->argument('file');
        if (!is_readable($path)) {
            $this->error("No encuentro el archivo {$path}");
            return self::FAILURE;
        }

        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_map(fn ($h) => Str::lower(trim($h)), array_shift($rows) ?? []);
        if (array_slice($header, 0, 5) !== array_slice(self::COLUMNS, 0, 5)) {
            $this->error('El encabezado debe ser: ' . implode(',', self::COLUMNS));
            return self::FAILURE;
        }

        $vehicles = Vehicle::all(['id', 'name']);
        $places = Place::where('is_active', true)->whereIn('type', ['hotel', 'stop'])->get(['id', 'name']);
        $catalog = $places->map(fn (Place $p) => ['id' => $p->id, 'name' => $p->name])->values()->all();

        $orders = [];
        $errors = 0;
        foreach ($rows as $n => $row) {
            $line = $n + 2;
            $row = array_map('trim', array_slice($row, 0, count(self::COLUMNS)));
            $r = array_combine(array_slice(self::COLUMNS, 0, count($row)), $row);

            $vehicle = $vehicles->first(fn (Vehicle $v) => Str::contains(Str::lower($v->name), Str::lower($r['unidad'] ?? '')));
            $type = Str::lower($r['tipo'] ?? '');
            $date = $r['fecha'] ?? '';
            $time = $r['hora'] ?? '';

            $problem = match (true) {
                !$vehicle => "unidad \"{$r['unidad']}\" no existe",
                !in_array($type, ServiceOrder::TYPES, true) => "tipo \"{$r['tipo']}\" (usa llegada, salida o traslado)",
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) => "fecha \"{$date}\" (usa AAAA-MM-DD)",
                !preg_match('/^\d{1,2}:\d{2}$/', $time) => "hora \"{$time}\" (usa HH:MM)",
                ($r['lugar'] ?? '') === '' => 'falta el lugar',
                default => null,
            };
            if ($problem) {
                $this->error("Línea {$line}: {$problem}");
                $errors++;
                continue;
            }

            $placeId = $matcher->match($r['lugar'], $catalog);
            $place = $placeId ? $places->firstWhere('id', $placeId) : null;
            $orders[] = [
                'vehicle_id' => $vehicle->id,
                'vehicle' => $vehicle->name,
                'service_date' => $date,
                'scheduled_time' => str_pad($time, 5, '0', STR_PAD_LEFT),
                'type' => $type,
                'place_id' => $place?->id,
                'place' => $place?->name,
                'place_text' => $r['lugar'],
                'terminal' => ($r['terminal'] ?? '') !== '' ? Str::upper($r['terminal']) : null,
                'pax' => is_numeric($r['pax'] ?? null) ? (int) $r['pax'] : null,
                'notes' => ($r['notas'] ?? '') !== '' ? $r['notas'] : null,
            ];
        }

        if ($errors) {
            $this->error("{$errors} línea(s) con error. Corrige el archivo; no se guardó nada.");
            return self::FAILURE;
        }

        $this->table(
            ['Fecha', 'Hora', 'Unidad', 'Tipo', 'En la orden', 'En el catálogo', 'Terminal', 'Pax'],
            array_map(fn ($o) => [
                $o['service_date'], $o['scheduled_time'], $o['vehicle'], $o['type'], $o['place_text'],
                $o['place'] ?? '⚠ no está, dalo de alta', $o['terminal'], $o['pax'],
            ], $orders)
        );

        if ($this->option('dry-run')) {
            $this->info('Prueba: no se guardó nada.');
            return self::SUCCESS;
        }

        foreach (collect($orders)->groupBy(fn ($o) => $o['vehicle_id'] . '|' . $o['service_date']) as $group) {
            ServiceOrder::where('vehicle_id', $group[0]['vehicle_id'])
                ->whereDate('service_date', $group[0]['service_date'])
                ->where('source', 'csv')
                ->delete();

            foreach ($group as $o) {
                ServiceOrder::create(collect($o)->except(['vehicle', 'place'])->all() + ['source' => 'csv']);
            }
        }

        $missing = collect($orders)->whereNull('place_id')->count();
        $this->info(count($orders) . ' orden(es) guardadas.' . ($missing ? " {$missing} con lugar que no está en el catálogo." : ''));

        return self::SUCCESS;
    }
}