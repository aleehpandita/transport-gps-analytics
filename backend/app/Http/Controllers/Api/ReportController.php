<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\TripPlaceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reportes del panel: resumen diario, viajes y paradas, por unidad y rango de fechas.
 *
 *   GET /api/reports/daily?from=2026-10-01&to=2026-10-22&vehicle_id=2
 *   GET /api/reports/trips?from=...&to=...&min_km=2&page=1&per_page=30
 *   GET /api/reports/stops?from=...&to=...&page=1
 *
 * - Viajes y paradas van paginados, con los totales de TODO el periodo en "totals".
 * - Con format=csv se descarga todo, sin paginar, armado por bloques para no cargar miles de
 *   viajes en memoria.
 * - Los días se cortan en hora de Cancún, y solo salen las unidades del usuario.
 */
class ReportController extends Controller
{
    protected const TIMEZONE = 'America/Cancun';
    // Rango máximo por consulta: dos meses
    protected const MAX_DAYS = 62;
    // Igual que el panel: movimientos más cortos no cuentan como viaje en el resumen
    protected const SHORT_TRIP_KM = 2;
    // Filas por página en viajes y paradas, si el panel no pide otra cantidad
    protected const PER_PAGE = 30;
    // Viajes por bloque al armar un CSV
    protected const CSV_CHUNK = 500;

    public function __construct(protected TripPlaceResolver $placeResolver)
    {
    }

    /**
     * Una fila por unidad y día. Sin paginar: son pocas filas (unidades × días).
     */
    public function daily(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request);

        $rows = $this->tripsQuery($f)
            ->get(['id', 'vehicle_id', 'started_at', 'ended_at', 'duration_seconds', 'distance_km', 'max_speed'])
            ->groupBy(fn (Trip $t) => $t->vehicle_id . '|' . $this->localDate($t->started_at))
            ->map(function (Collection $day) use ($f) {
                $first = $day->first();
                $last = $day->last();
                $workday = $last->ended_at->getTimestamp() - $first->started_at->getTimestamp();
                $driving = (int) $day->sum('duration_seconds');

                return [
                    'vehicle_id' => $first->vehicle_id,
                    'vehicle' => $f['vehicles'][$first->vehicle_id],
                    'date' => $this->localDate($first->started_at),
                    'trips' => $day->where('distance_km', '>=', self::SHORT_TRIP_KM)->count(),
                    'km' => round((float) $day->sum('distance_km'), 1),
                    'driving_seconds' => $driving,
                    'first_start' => $first->started_at->toIso8601String(),
                    'last_end' => $last->ended_at->toIso8601String(),
                    'workday_seconds' => $workday,
                    'stops' => max(0, $day->count() - 1),
                    'stopped_seconds' => max(0, $workday - $driving),
                    'max_speed' => round((float) $day->max('max_speed'), 0),
                ];
            })
            ->sortBy(fn (array $r) => $r['date'] . '|' . $r['vehicle'])
            ->values();

        if ($f['format'] === 'csv') {
            return $this->csv($this->filename('resumen-diario', $f),
                ['Unidad', 'Fecha', 'Viajes', 'Km', 'Tiempo en ruta (min)', 'Primera salida', 'Última llegada', 'Jornada (min)', 'Paradas', 'Tiempo detenido (min)', 'Velocidad máx (km/h)'],
                function () use ($rows) {
                    foreach ($rows as $r) {
                        yield [
                            $r['vehicle'], $r['date'], $r['trips'], $r['km'], round($r['driving_seconds'] / 60),
                            $this->localTime($r['first_start']), $this->localTime($r['last_end']),
                            round($r['workday_seconds'] / 60), $r['stops'], round($r['stopped_seconds'] / 60), $r['max_speed'],
                        ];
                    }
                },
            );
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Viajes del periodo, paginados, con lugar de origen y destino.
     */
    public function trips(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request);
        $base = $this->tripsQuery($f);
        $query = (clone $base)->where('distance_km', '>=', $f['min_km']);

        if ($f['format'] === 'csv') {
            return $this->csv($this->filename('viajes', $f),
                ['Unidad', 'Fecha', 'Salida', 'Llegada', 'Origen', 'Destino', 'Km', 'Duración (min)', 'Velocidad máx (km/h)'],
                function () use ($query, $f) {
                    // Por bloques: los lugares se resuelven bloque por bloque, nunca todo junto
                    foreach ($query->with(['originZone', 'destinationZone'])->lazy(self::CSV_CHUNK)->chunk(self::CSV_CHUNK) as $chunk) {
                        foreach ($this->tripRows($chunk->collect(), $f) as $r) {
                            yield [
                                $r['vehicle'], $this->localDate($r['started_at']), $this->localTime($r['started_at']), $this->localTime($r['ended_at']),
                                $r['origin_place'] ?? $r['origin_zone'] ?? '', $r['destination_place'] ?? $r['destination_zone'] ?? '',
                                $r['distance_km'], round($r['duration_seconds'] / 60), $r['max_speed'] ?? '',
                            ];
                        }
                    }
                },
            );
        }

        // Totales del periodo completo, no de la página
        // reorder(): sin ORDER BY, que MySQL y Postgres no aceptan junto a un count sin GROUP BY
        $totals = (clone $query)->reorder()->toBase()
            ->selectRaw('count(*) as trips, coalesce(sum(distance_km), 0) as km, coalesce(sum(duration_seconds), 0) as duration_seconds')
            ->first();

        $page = $query->with(['originZone', 'destinationZone'])->paginate($f['per_page'], ['*'], 'page', $f['page']);

        return response()->json([
            'data' => $this->tripRows($page->getCollection(), $f),
            'meta' => $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
            'totals' => [
                'trips' => (int) $totals->trips,
                'km' => round((float) $totals->km, 1),
                'duration_seconds' => (int) $totals->duration_seconds,
                // Movimientos cortos del periodo, para que el panel diga cuántos está ocultando
                'short_trips' => (clone $base)->where('distance_km', '<', self::SHORT_TRIP_KM)->count(),
            ],
        ]);
    }

    /**
     * Paradas del periodo, paginadas: el tiempo entre que termina un viaje y empieza el
     * siguiente, el mismo día y la misma unidad.
     */
    public function stops(Request $request): JsonResponse|StreamedResponse
    {
        $f = $this->filters($request);

        // Solo las columnas necesarias: aunque sean miles de viajes, pesan poco
        $trips = $this->tripsQuery($f)->get(['id', 'vehicle_id', 'started_at', 'ended_at', 'destination_zone_id']);

        $stops = collect();
        foreach ($trips->groupBy(fn (Trip $t) => $t->vehicle_id . '|' . $this->localDate($t->started_at)) as $day) {
            $day = $day->values();
            for ($i = 0; $i < $day->count() - 1; $i++) {
                $stops->push(['before' => $day[$i], 'after' => $day[$i + 1]]);
            }
        }
        $stops = $stops->sortBy(fn ($s) => $s['before']->ended_at->getTimestamp())->values();

        if ($f['format'] === 'csv') {
            return $this->csv($this->filename('paradas', $f),
                ['Unidad', 'Fecha', 'Desde', 'Hasta', 'Duración (min)', 'Lugar', 'Zona'],
                function () use ($stops, $f) {
                    foreach ($stops->chunk(self::CSV_CHUNK) as $chunk) {
                        foreach ($this->stopRows($chunk, $f) as $r) {
                            yield [
                                $r['vehicle'], $this->localDate($r['started_at']), $this->localTime($r['started_at']), $this->localTime($r['ended_at']),
                                round($r['duration_seconds'] / 60), $r['place'] ?? '', $r['zone'] ?? '',
                            ];
                        }
                    }
                },
            );
        }

        $total = $stops->count();
        $lastPage = max(1, (int) ceil($total / $f['per_page']));
        $current = min($f['page'], $lastPage);
        $slice = $stops->slice(($current - 1) * $f['per_page'], $f['per_page']);

        return response()->json([
            'data' => $this->stopRows($slice, $f),
            'meta' => $this->meta($current, $lastPage, $f['per_page'], $total),
            'totals' => [
                'stops' => $total,
                'duration_seconds' => (int) $stops->sum(fn ($s) => $s['after']->started_at->getTimestamp() - $s['before']->ended_at->getTimestamp()),
            ],
        ]);
    }

    /**
     * @param Collection<int, Trip> $trips
     */
    protected function tripRows(Collection $trips, array $f): Collection
    {
        $places = $this->placeResolver->resolve($trips);

        return $trips->map(fn (Trip $t) => [
            'id' => $t->id,
            'vehicle_id' => $t->vehicle_id,
            'vehicle' => $f['vehicles'][$t->vehicle_id],
            'started_at' => $t->started_at->toIso8601String(),
            'ended_at' => $t->ended_at->toIso8601String(),
            'duration_seconds' => (int) $t->duration_seconds,
            'distance_km' => round((float) $t->distance_km, 1),
            'max_speed' => $t->max_speed !== null ? round((float) $t->max_speed) : null,
            'origin_zone' => $t->originZone?->name,
            'destination_zone' => $t->destinationZone?->name,
            'origin_place' => $places[$t->id]['origin_place'] ?? null,
            'destination_place' => $places[$t->id]['destination_place'] ?? null,
        ])->values();
    }

    /**
     * @param Collection<int, array{before: Trip, after: Trip}> $stops
     */
    protected function stopRows(Collection $stops, array $f): Collection
    {
        // Lugares y zonas solo de los viajes de esta página
        $before = Trip::with('destinationZone')->whereIn('id', $stops->map(fn ($s) => $s['before']->id)->values())->get()->keyBy('id');
        $places = $this->placeResolver->resolve($before->values());

        return $stops->map(fn ($s) => [
            'vehicle_id' => $s['before']->vehicle_id,
            'vehicle' => $f['vehicles'][$s['before']->vehicle_id],
            'started_at' => $s['before']->ended_at->toIso8601String(),
            'ended_at' => $s['after']->started_at->toIso8601String(),
            'duration_seconds' => $s['after']->started_at->getTimestamp() - $s['before']->ended_at->getTimestamp(),
            'place' => $places[$s['before']->id]['destination_place'] ?? null,
            'zone' => $before->get($s['before']->id)?->destinationZone?->name,
        ])->values();
    }

    /**
     * Valida los filtros comunes.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, vehicles: Collection<int, string>, format: string, page: int, per_page: int, min_km: float}
     */
    protected function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'vehicle_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:500'],
            'min_km' => ['nullable', 'numeric', 'min:0'],
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['from'], self::TIMEZONE)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['to'], self::TIMEZONE)->endOfDay();

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'El rango máximo es de ' . self::MAX_DAYS . ' días.']);
        }

        // Solo las unidades del usuario; una unidad ajena da una lista vacía, no un error
        $vehicles = $request->user()->visibleVehicles()
            ->when(isset($validated['vehicle_id']), fn ($q) => $q->where('id', $validated['vehicle_id']))
            ->orderBy('name')
            ->pluck('name', 'id');

        return [
            'from' => $from,
            'to' => $to,
            'vehicles' => $vehicles,
            'format' => $validated['format'] ?? 'json',
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? self::PER_PAGE),
            'min_km' => (float) ($validated['min_km'] ?? 0),
        ];
    }

    /**
     * @return Builder<Trip>
     */
    protected function tripsQuery(array $f): Builder
    {
        return Trip::query()
            ->whereIn('vehicle_id', $f['vehicles']->keys())
            ->where('data_source', 'real')
            ->whereBetween('started_at', [$f['from']->utc(), $f['to']->utc()])
            ->orderBy('vehicle_id')
            ->orderBy('started_at');
    }

    protected function meta(int $page, int $lastPage, int $perPage, int $total): array
    {
        return ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total];
    }

    protected function filename(string $name, array $f): string
    {
        return "{$name}_{$f['from']->toDateString()}_{$f['to']->toDateString()}.csv";
    }

    protected function localDate($time): string
    {
        return CarbonImmutable::parse($time)->tz(self::TIMEZONE)->toDateString();
    }

    protected function localTime(string $iso): string
    {
        return CarbonImmutable::parse($iso)->tz(self::TIMEZONE)->format('H:i');
    }

    /**
     * CSV para Excel: UTF-8 con BOM (si no, Excel rompe los acentos) y separado por comas.
     * Las filas llegan de un generador, así que se escriben conforme se arman.
     *
     * @param callable(): iterable<array> $rows
     */
    protected function csv(string $filename, array $headers, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}