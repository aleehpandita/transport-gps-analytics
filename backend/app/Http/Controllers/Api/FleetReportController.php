<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ExportsReports;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Geofence;
use App\Models\GeofenceVisit;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes de la empresa (EkMira BASE): van con el middleware company.context, así que un
 * cliente ve solo lo suyo y un admin primero elige un cliente con "Ver como".
 *
 *   GET /api/reports/geofence-visits?from=&to=&vehicle_id=&geofence_id=&page=
 *       Entradas y salidas de las geocercas, con el tiempo dentro, y un resumen por
 *       unidad y geocerca.
 *   GET /api/reports/distance?from=&to=&vehicle_id=
 *       Km por unidad y día de toda la flota (los días sin movimiento van en 0).
 *   GET /api/reports/alerts?from=&to=&vehicle_id=&type=&page=
 *       Alertas del periodo: cuántas por unidad y tipo, y la lista.
 *
 * format=csv descarga el detalle; format=xlsx devuelve las hojas para que el panel arme el
 * Excel (resumen y detalle).
 */
class FleetReportController extends Controller
{
    use ExportsReports;

    protected const MAX_DAYS = 62;
    protected const PER_PAGE = 30;
    // Igual que el resumen diario: movimientos más cortos no cuentan como viaje
    protected const SHORT_TRIP_KM = 2;

    // ---------------------------------------------------------------- Entradas y salidas

    public function geofenceVisits(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request, ['geofence_id' => ['nullable', 'integer']]);
        $geofences = Geofence::where('company_id', $f['company_id'])->orderBy('name')->get(['id', 'name', 'color'])->keyBy('id');
        $now = now()->getTimestamp();

        // Las visitas que tocan el periodo: entraron antes de que terminara y salieron
        // después de que empezó (o siguen dentro)
        $query = GeofenceVisit::query()
            ->where('company_id', $f['company_id'])
            ->whereIn('vehicle_id', $f['vehicles']->keys())
            ->when($f['geofence_id'], fn ($q, $id) => $q->where('geofence_id', $id))
            ->where('entered_at', '<=', $f['to']->utc())
            ->where(fn ($q) => $q->whereNull('exited_at')->orWhere('exited_at', '>=', $f['from']->utc()))
            ->orderBy('entered_at')
            ->orderBy('id');

        $all = (clone $query)->get(['id', 'vehicle_id', 'geofence_id', 'entered_at', 'exited_at', 'duration_seconds', 'started_inside']);

        $row = fn (GeofenceVisit $v) => [
            'id' => $v->id,
            'vehicle_id' => $v->vehicle_id,
            'vehicle' => $f['vehicles'][$v->vehicle_id],
            'geofence_id' => $v->geofence_id,
            'geofence' => $geofences[$v->geofence_id]->name ?? '',
            'color' => $geofences[$v->geofence_id]->color ?? null,
            'entered_at' => $v->entered_at->toIso8601String(),
            'exited_at' => $v->exited_at?->toIso8601String(),
            'duration_seconds' => $v->secondsInside($now),
            'started_inside' => $v->started_inside,
            'inside' => $v->exited_at === null,
        ];

        $summary = $all->groupBy(fn (GeofenceVisit $v) => $v->vehicle_id . '|' . $v->geofence_id)
            ->map(function (Collection $visits) use ($f, $geofences, $now) {
                $first = $visits->first();
                $seconds = (int) $visits->sum(fn (GeofenceVisit $v) => $v->secondsInside($now));

                return [
                    'vehicle_id' => $first->vehicle_id,
                    'vehicle' => $f['vehicles'][$first->vehicle_id],
                    'geofence_id' => $first->geofence_id,
                    'geofence' => $geofences[$first->geofence_id]->name ?? '',
                    'visits' => $visits->count(),
                    'duration_seconds' => $seconds,
                    'average_seconds' => (int) round($seconds / $visits->count()),
                ];
            })
            ->sortBy(fn (array $r) => $r['vehicle'] . '|' . $r['geofence'])
            ->values();

        if ($f['format'] !== 'json') {
            return $this->export($f['format'], $this->periodFilename('entradas-salidas', $f['from'], $f['to']), [
                [
                    'name' => 'Resumen',
                    'headers' => ['Unidad', 'Geocerca', 'Visitas', 'Tiempo dentro (min)', 'Promedio por visita (min)'],
                    'rows' => function () use ($summary) {
                        foreach ($summary as $r) {
                            yield [$r['vehicle'], $r['geofence'], $r['visits'], $this->minutes($r['duration_seconds']), $this->minutes($r['average_seconds'])];
                        }
                    },
                ],
                [
                    'name' => 'Entradas y salidas',
                    'csv' => true,
                    'headers' => ['Unidad', 'Geocerca', 'Fecha de entrada', 'Hora de entrada', 'Fecha de salida', 'Hora de salida', 'Tiempo dentro (min)', 'Nota'],
                    'rows' => function () use ($all, $row) {
                        foreach ($all as $visit) {
                            $r = $row($visit);
                            yield [
                                $r['vehicle'], $r['geofence'],
                                $this->localDate($r['entered_at']), $this->localTime($r['entered_at']),
                                $r['exited_at'] ? $this->localDate($r['exited_at']) : '', $r['exited_at'] ? $this->localTime($r['exited_at']) : '',
                                $this->minutes($r['duration_seconds']),
                                $this->visitNote($r),
                            ];
                        }
                    },
                ],
            ]);
        }

        $total = $all->count();
        $lastPage = max(1, (int) ceil($total / $f['per_page']));
        $current = min($f['page'], $lastPage);

        return response()->json([
            'data' => $all->slice(($current - 1) * $f['per_page'], $f['per_page'])->map($row)->values(),
            'meta' => $this->meta($current, $lastPage, $f['per_page'], $total),
            'summary' => $summary,
            'totals' => [
                'visits' => $total,
                'duration_seconds' => (int) $summary->sum('duration_seconds'),
            ],
            'geofences' => $geofences->values()->map(fn (Geofence $g) => ['id' => $g->id, 'name' => $g->name, 'color' => $g->color]),
        ]);
    }

    // ---------------------------------------------------------------- Distancia

    public function distance(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request);

        $days = [];
        for ($d = $f['from']; $d->lte($f['to']); $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        $byKey = Trip::query()
            ->whereIn('vehicle_id', $f['vehicles']->keys())
            ->where('data_source', 'real')
            ->whereBetween('started_at', [$f['from']->utc(), $f['to']->utc()])
            ->get(['vehicle_id', 'started_at', 'distance_km'])
            ->groupBy(fn (Trip $t) => $t->vehicle_id . '|' . $this->localDate($t->started_at));

        // Todas las unidades y todos los días, aunque no se hayan movido
        $data = $f['vehicles']->map(function (string $name, int $id) use ($days, $byKey) {
            $km = [];
            $trips = [];
            foreach ($days as $day) {
                $dayTrips = $byKey->get("{$id}|{$day}") ?? collect();
                $km[$day] = round((float) $dayTrips->sum('distance_km'), 1);
                $trips[$day] = $dayTrips->where('distance_km', '>=', self::SHORT_TRIP_KM)->count();
            }
            $active = count(array_filter($km, fn ($v) => $v > 0));

            return [
                'vehicle_id' => $id,
                'vehicle' => $name,
                'km' => $km,
                'trips' => $trips,
                'total_km' => round(array_sum($km), 1),
                'total_trips' => array_sum($trips),
                'active_days' => $active,
                'average_km' => $active > 0 ? round(array_sum($km) / $active, 1) : 0,
            ];
        })->values();

        $byDay = [];
        foreach ($days as $day) {
            $byDay[$day] = round((float) $data->sum(fn ($r) => $r['km'][$day]), 1);
        }

        if ($f['format'] !== 'json') {
            return $this->export($f['format'], $this->periodFilename('distancia', $f['from'], $f['to']), [
                [
                    'name' => 'Km por día',
                    'headers' => array_merge(['Unidad'], $days, ['Total km']),
                    'rows' => function () use ($data, $days, $byDay) {
                        foreach ($data as $r) {
                            yield array_merge([$r['vehicle']], array_map(fn ($d) => $r['km'][$d], $days), [$r['total_km']]);
                        }
                        yield array_merge(['Total flota'], array_values($byDay), [round(array_sum($byDay), 1)]);
                    },
                ],
                [
                    'name' => 'Por unidad',
                    'headers' => ['Unidad', 'Km', 'Viajes', 'Días con movimiento', 'Promedio km por día con movimiento'],
                    'rows' => function () use ($data) {
                        foreach ($data as $r) {
                            yield [$r['vehicle'], $r['total_km'], $r['total_trips'], $r['active_days'], $r['average_km']];
                        }
                    },
                ],
                [
                    'name' => 'Detalle',
                    'csv' => true,
                    'headers' => ['Unidad', 'Fecha', 'Km', 'Viajes'],
                    'rows' => function () use ($data, $days) {
                        foreach ($data as $r) {
                            foreach ($days as $day) {
                                yield [$r['vehicle'], $day, $r['km'][$day], $r['trips'][$day]];
                            }
                        }
                    },
                ],
            ]);
        }

        return response()->json([
            'days' => $days,
            'data' => $data,
            'totals' => [
                'km' => round(array_sum($byDay), 1),
                'trips' => (int) $data->sum('total_trips'),
                'by_day' => $byDay,
            ],
        ]);
    }

    // ---------------------------------------------------------------- Alertas

    public function alerts(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request, ['type' => ['nullable', Rule::in(AlertRule::TYPES)]]);

        $query = Alert::query()
            ->where('company_id', $f['company_id'])
            ->whereIn('vehicle_id', $f['vehicles']->keys())
            ->when($f['type'], fn ($q, $type) => $q->where('type', $type))
            ->whereBetween('triggered_at', [$f['from']->utc(), $f['to']->utc()]);

        // Conteo por unidad y tipo, sin traer los avisos
        $counts = (clone $query)->toBase()
            ->selectRaw('vehicle_id, type, count(*) as n')
            ->groupBy('vehicle_id', 'type')
            ->get();

        $types = collect(AlertRule::TYPES)->filter(fn ($t) => $counts->contains('type', $t))->values();
        $summary = $counts->groupBy('vehicle_id')
            ->map(function (Collection $rows, $vehicleId) use ($f, $types) {
                $byType = $types->mapWithKeys(fn ($t) => [$t => (int) ($rows->firstWhere('type', $t)->n ?? 0)])->all();

                return [
                    'vehicle_id' => (int) $vehicleId,
                    'vehicle' => $f['vehicles'][$vehicleId],
                    'counts' => $byType,
                    'total' => array_sum($byType),
                ];
            })
            ->sortBy('vehicle')
            ->values();

        $typeTotals = $types->mapWithKeys(fn ($t) => [$t => (int) $counts->where('type', $t)->sum('n')])->all();
        $typeList = $types->map(fn ($t) => ['type' => $t, 'label' => AlertRule::LABELS[$t]])->values();

        $detail = (clone $query)->with('rule:id,name')->orderBy('triggered_at')->orderBy('id');
        $row = fn (Alert $a) => [
            'id' => $a->id,
            'vehicle_id' => $a->vehicle_id,
            'vehicle' => $f['vehicles'][$a->vehicle_id],
            'type' => $a->type,
            'type_label' => AlertRule::LABELS[$a->type] ?? $a->type,
            'rule' => $a->rule?->name,
            'triggered_at' => $a->triggered_at->toIso8601String(),
            'message' => $a->message,
            'value' => $a->value,
            'latitude' => $a->latitude,
            'longitude' => $a->longitude,
        ];

        if ($f['format'] !== 'json') {
            return $this->export($f['format'], $this->periodFilename('alertas', $f['from'], $f['to']), [
                [
                    'name' => 'Por unidad y tipo',
                    'headers' => array_merge(['Unidad'], $typeList->pluck('label')->all(), ['Total']),
                    'rows' => function () use ($summary, $types, $typeTotals) {
                        foreach ($summary as $r) {
                            yield array_merge([$r['vehicle']], array_values($r['counts']), [$r['total']]);
                        }
                        if ($summary->isNotEmpty()) {
                            yield array_merge(['Total'], array_values($typeTotals), [array_sum($typeTotals)]);
                        }
                    },
                ],
                [
                    'name' => 'Detalle',
                    'csv' => true,
                    'headers' => ['Unidad', 'Fecha', 'Hora', 'Tipo', 'Regla', 'Aviso', 'Valor', 'Latitud', 'Longitud'],
                    'rows' => function () use ($detail, $row) {
                        foreach ($detail->lazy(500) as $alert) {
                            $r = $row($alert);
                            yield [
                                $r['vehicle'], $this->localDate($r['triggered_at']), $this->localTime($r['triggered_at']),
                                $r['type_label'], $r['rule'] ?? '', $r['message'], $r['value'] ?? '', $r['latitude'], $r['longitude'],
                            ];
                        }
                    },
                ],
            ]);
        }

        $page = $detail->paginate($f['per_page'], ['*'], 'page', $f['page']);

        return response()->json([
            'data' => $page->getCollection()->map($row)->values(),
            'meta' => $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
            'types' => $typeList,
            'summary' => $summary,
            'totals' => ['by_type' => (object) $typeTotals, 'total' => array_sum($typeTotals)],
        ]);
    }

    // ---------------------------------------------------------------- Comunes

    /**
     * @return array{company_id: int, from: CarbonImmutable, to: CarbonImmutable, vehicles: Collection<int, string>, format: string, page: int, per_page: int, geofence_id: ?int, type: ?string}
     */
    protected function filters(Request $request, array $extra = []): array
    {
        $validated = $request->validate($extra + [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'vehicle_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(['json', ...self::EXPORT_FORMATS])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);

        $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], self::TIMEZONE)->startOfDay();
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], self::TIMEZONE)->endOfDay();

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'El rango máximo es de ' . self::MAX_DAYS . ' días.']);
        }

        $companyId = (int) $request->attributes->get('company_id');

        // Solo las unidades de la empresa; una unidad ajena da un reporte vacío, no un error
        $vehicles = $request->user()->visibleVehicles()
            ->where('company_id', $companyId)
            ->when(isset($validated['vehicle_id']), fn ($q) => $q->whereKey($validated['vehicle_id']))
            ->orderBy('name')
            ->pluck('name', 'id');

        return [
            'company_id' => $companyId,
            'from' => $from,
            'to' => $to,
            'vehicles' => $vehicles,
            'format' => $validated['format'] ?? 'json',
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? self::PER_PAGE),
            'geofence_id' => isset($validated['geofence_id']) ? (int) $validated['geofence_id'] : null,
            'type' => $validated['type'] ?? null,
        ];
    }

    protected function meta(int $page, int $lastPage, int $perPage, int $total): array
    {
        return ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total];
    }

    protected function minutes(int $seconds): int
    {
        return (int) round($seconds / 60);
    }

    protected function visitNote(array $r): string
    {
        return match (true) {
            $r['inside'] && $r['started_inside'] => 'Ya estaba dentro al empezar el registro; sigue dentro',
            $r['inside'] => 'Sigue dentro',
            $r['started_inside'] => 'Ya estaba dentro al empezar el registro',
            default => '',
        };
    }
}
