<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduledServiceRequest;
use App\Http\Resources\ScheduledServiceResource;
use App\Models\ScheduledService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduledServiceController extends Controller
{
    /**
     * Servicios programados, con filtros opcionales.
     *
     *   GET /api/scheduled-services
     *   GET /api/scheduled-services?from=2026-10-06T14:00:00Z
     *   GET /api/scheduled-services?from=...&to=...&vehicle_id=1&per_page=12
     *
     * - from / to: rango sobre scheduled_at. Fechas ISO 8601; sin zona horaria se asumen UTC.
     * - Con rango, el orden es cronológico (lo próximo primero), que es lo que necesita el
     *   dashboard. Sin rango, se mantiene el orden anterior: lo más reciente primero.
     * - per_page: tamaño de página, de 1 a 200 (50 por defecto).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => array_merge(
                ['nullable', 'date'],
                $request->filled('from') ? ['after_or_equal:from'] : []
            ),
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ScheduledService::with(['vehicle', 'destinationZone']);

        if (isset($validated['vehicle_id'])) {
            $query->where('vehicle_id', $validated['vehicle_id']);
        }

        if (isset($validated['from'])) {
            $query->where('scheduled_at', '>=', CarbonImmutable::parse($validated['from'])->utc());
        }

        if (isset($validated['to'])) {
            $query->where('scheduled_at', '<=', CarbonImmutable::parse($validated['to'])->utc());
        }

        if (isset($validated['from']) || isset($validated['to'])) {
            $query->orderBy('scheduled_at');
        } else {
            $query->orderByDesc('scheduled_at');
        }

        return ScheduledServiceResource::collection(
            $query->paginate($validated['per_page'] ?? 50)->withQueryString()
        );
    }

    public function store(StoreScheduledServiceRequest $request): ScheduledServiceResource
    {
        $service = ScheduledService::create($request->validated());

        return new ScheduledServiceResource($service->load(['vehicle', 'destinationZone']));
    }
}