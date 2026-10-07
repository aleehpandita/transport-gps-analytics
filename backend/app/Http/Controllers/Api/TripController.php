<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    /**
     * Viajes de toda la flota en un día operativo de Cancún.
     *
     *   GET /api/trips                         hoy
     *   GET /api/trips?date=2026-10-06         un día específico
     *   GET /api/trips?data_source=synthetic   viajes sintéticos (por defecto, reales)
     *
     * El día se corta en America/Cancun, no en UTC: la base guarda UTC, y un viaje a
     * las 20:00 en Cancún ya es del día siguiente en UTC. Mismo criterio que
     * distance_today_km en VehicleController.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'data_source' => ['nullable', Rule::in(['real', 'synthetic'])],
        ]);

        $day = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], 'America/Cancun')
            : CarbonImmutable::now('America/Cancun');

        $trips = Trip::with(['vehicle', 'originZone', 'destinationZone'])
            ->where('data_source', $validated['data_source'] ?? 'real')
            ->whereBetween('started_at', [
                $day->startOfDay()->utc(),
                $day->endOfDay()->utc(),
            ])
            ->orderByDesc('started_at')
            ->get();

        return TripResource::collection($trips);
    }
}