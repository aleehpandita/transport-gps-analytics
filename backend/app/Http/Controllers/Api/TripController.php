<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Services\TripPlaceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    /**
     * Viajes de las unidades del usuario en un día operativo de Cancún.
     *
     *   GET /api/trips                         hoy
     *   GET /api/trips?date=2026-10-06         un día específico
     *   GET /api/trips?data_source=synthetic   viajes sintéticos (por defecto, reales)
     *
     * El día se corta en America/Cancun, no en UTC: la base guarda UTC, y un viaje a
     * las 20:00 en Cancún ya es del día siguiente en UTC. Mismo criterio que
     * distance_today_km en VehicleController.
     *
     * Cada viaje trae, además de sus zonas, el lugar del catálogo donde empezó y terminó
     * (origin_place, destination_place), o null si no fue en un lugar conocido.
     */
    public function index(Request $request, TripPlaceResolver $placeResolver): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'data_source' => ['nullable', Rule::in(['real', 'synthetic'])],
        ]);

        $day = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], 'America/Cancun')
            : CarbonImmutable::now('America/Cancun');

        $query = Trip::with(['vehicle', 'originZone', 'destinationZone'])
            ->where('data_source', $validated['data_source'] ?? 'real')
            ->whereBetween('started_at', [
                $day->startOfDay()->utc(),
                $day->endOfDay()->utc(),
            ])
            ->orderByDesc('started_at');

        $trips = $request->user()->restrictToVisibleVehicles($query)->get();
        $places = $placeResolver->resolve($trips);

        return response()->json([
            'data' => $trips->map(fn (Trip $trip) => array_merge(
                (new TripResource($trip))->resolve($request),
                $places[$trip->id] ?? ['origin_place' => null, 'destination_place' => null],
            ))->values(),
        ]);
    }
}