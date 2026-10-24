<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Eventos de las unidades del usuario, del más reciente al más viejo.
 *
 *   GET /api/events?from=2026-10-23&to=2026-10-23&vehicle_id=2&type=alerts&page=1
 *
 * type: uno de VehicleEvent::TYPES, o "alerts" para exceso, parada larga y sin señal.
 * "counts" trae cuántos hay de cada tipo en el periodo, sin el filtro de tipo, para que el
 * panel muestre los números en los filtros.
 */
class EventController extends Controller
{
    protected const TIMEZONE = 'America/Cancun';
    protected const MAX_DAYS = 62;
    protected const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'vehicle_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in([...VehicleEvent::TYPES, 'alerts'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $validated['from'], self::TIMEZONE)->startOfDay();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $validated['to'], self::TIMEZONE)->endOfDay();

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'El rango máximo es de ' . self::MAX_DAYS . ' días.']);
        }

        $vehicles = $request->user()->visibleVehicles()
            ->when(isset($validated['vehicle_id']), fn ($q) => $q->where('id', $validated['vehicle_id']))
            ->pluck('name', 'id');

        $base = VehicleEvent::query()
            ->whereIn('vehicle_id', $vehicles->keys())
            ->whereBetween('started_at', [$from->utc(), $to->utc()]);

        $counts = (clone $base)->toBase()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($n) => (int) $n);

        $type = $validated['type'] ?? null;
        $page = (clone $base)
            ->when($type === 'alerts', fn ($q) => $q->whereIn('type', VehicleEvent::ALERTS))
            ->when($type && $type !== 'alerts', fn ($q) => $q->where('type', $type))
            ->with(['zone:id,name', 'place:id,name'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? self::PER_PAGE, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn (VehicleEvent $e) => [
                'id' => $e->id,
                'vehicle_id' => $e->vehicle_id,
                'vehicle' => $vehicles[$e->vehicle_id],
                'type' => $e->type,
                'started_at' => $e->started_at->toIso8601String(),
                'ended_at' => $e->ended_at?->toIso8601String(),
                'duration_seconds' => $e->ended_at ? $e->ended_at->getTimestamp() - $e->started_at->getTimestamp() : null,
                'latitude' => $e->latitude,
                'longitude' => $e->longitude,
                'zone' => $e->zone?->name,
                'place' => $e->place?->name,
                'value' => $e->value,
                'data' => $e->data,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'counts' => (object) $counts->all(),
        ]);
    }
}