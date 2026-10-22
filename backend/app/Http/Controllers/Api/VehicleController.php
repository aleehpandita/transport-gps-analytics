<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\VehicleResource;
use App\Models\Place;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Unidades del usuario con sesión. Un cliente solo ve las suyas: si pide una unidad
 * ajena recibe 404, como si no existiera.
 */
class VehicleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        [$startOfDay, $endOfDay] = $this->todayRangeInCancun();

        return VehicleResource::collection(
            $request->user()->visibleVehicles()
                ->where('active', true)
                ->with('latestPosition')
                ->withSum(['trips as distance_today_km' => function ($query) use ($startOfDay, $endOfDay) {
                    $query->whereBetween('started_at', [$startOfDay, $endOfDay])->where('data_source', 'real');
                }], 'distance_km')
                ->get()
        );
    }

    public function show(Request $request, Vehicle $vehicle): VehicleResource
    {
        $this->ensureVisible($request, $vehicle);

        [$startOfDay, $endOfDay] = $this->todayRangeInCancun();

        $vehicle->load('latestPosition');
        $vehicle->loadSum(['trips as distance_today_km' => function ($query) use ($startOfDay, $endOfDay) {
            $query->whereBetween('started_at', [$startOfDay, $endOfDay])->where('data_source', 'real');
        }], 'distance_km');

        return new VehicleResource($vehicle);
    }

    public function latestPosition(Request $request, Vehicle $vehicle): PositionResource|JsonResponse
    {
        $this->ensureVisible($request, $vehicle);

        $position = $vehicle->latestPosition;

        if (!$position) {
            return response()->json(['message' => 'Sin posiciones registradas para este vehículo.'], 404);
        }

        return new PositionResource($position);
    }

    public function positions(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        $this->ensureVisible($request, $vehicle);

        $query = $vehicle->positions()->orderByDesc('device_time');

        if ($request->filled('data_source')) {
            $query->where('data_source', $request->string('data_source'));
        }

        if ($request->filled('from')) {
            $query->where('device_time', '>=', $request->string('from'));
        }

        if ($request->filled('to')) {
            $query->where('device_time', '<=', $request->string('to'));
        }

        $perPage = min((int) $request->integer('per_page', 100), 500);

        return PositionResource::collection($query->paginate($perPage));
    }

    public function trips(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        $this->ensureVisible($request, $vehicle);

        $query = $vehicle->trips()->orderByDesc('started_at');

        if ($request->filled('data_source')) {
            $query->where('data_source', $request->string('data_source'));
        }

        return TripResource::collection($query->paginate(50));
    }

    /**
     * El recorrido completo de un día (hora Cancún), para el historial y la repetición.
     *
     *   GET /api/vehicles/2/route?date=2026-10-21
     *
     * Las posiciones van compactas, como arreglos [timestamp, lat, lng, km/h, rumbo]: un día de
     * Kevs1 tiene miles, y así la respuesta pesa la tercera parte que con objetos.
     * Cada viaje trae, además de sus zonas, el lugar del catálogo donde empezó y terminó.
     */
    public function route(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->ensureVisible($request, $vehicle);

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $day = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], 'America/Cancun')
            : CarbonImmutable::now('America/Cancun');

        $from = $day->startOfDay()->utc();
        $to = $day->endOfDay()->utc();

        $positions = $vehicle->positions()
            ->where('valid', true)
            ->whereBetween('device_time', [$from, $to])
            ->orderBy('device_time')
            ->get(['device_time', 'latitude', 'longitude', 'speed', 'course']);

        $points = $positions->map(fn ($p) => [
            $p->device_time->getTimestamp(),
            round((float) $p->latitude, 6),
            round((float) $p->longitude, 6),
            round((float) $p->speed, 1),
            (int) round((float) $p->course),
        ])->values();

        // Coordenadas por segundo, para ubicar el inicio y el fin de cada viaje
        $byTimestamp = $positions->keyBy(fn ($p) => $p->device_time->getTimestamp());
        $places = Place::where('is_active', true)->get(['name', 'latitude', 'longitude', 'radius_m']);

        $trips = $vehicle->trips()
            ->with(['originZone', 'destinationZone'])
            ->where('data_source', 'real')
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at')
            ->get()
            ->map(function ($trip) use ($request, $byTimestamp, $places) {
                $start = $byTimestamp->get($trip->started_at->getTimestamp());
                $end = $byTimestamp->get($trip->ended_at->getTimestamp());

                return array_merge((new TripResource($trip))->resolve($request), [
                    'origin_place' => $start ? $this->placeNameAt($places, $start->latitude, $start->longitude) : null,
                    'destination_place' => $end ? $this->placeNameAt($places, $end->latitude, $end->longitude) : null,
                ]);
            });

        return response()->json([
            'data' => [
                'date' => $day->toDateString(),
                'points' => $points,
                'trips' => $trips,
            ],
        ]);
    }

    /**
     * Nombre del lugar activo más cercano que contiene el punto, o null.
     */
    protected function placeNameAt($places, float $lat, float $lng): ?string
    {
        $best = null;
        $bestMeters = INF;

        foreach ($places as $place) {
            $dLat = deg2rad($place->latitude - $lat);
            $dLng = deg2rad($place->longitude - $lng);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($place->latitude)) * sin($dLng / 2) ** 2;
            $meters = 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));

            if ($meters <= $place->radius_m && $meters < $bestMeters) {
                $best = $place->name;
                $bestMeters = $meters;
            }
        }

        return $best;
    }

    protected function todayRangeInCancun(): array
    {
        return [
            now('America/Cancun')->startOfDay()->utc(),
            now('America/Cancun')->endOfDay()->utc(),
        ];
    }

    /**
     * 404 y no 403 a propósito: a un cliente no se le confirma que la unidad existe.
     */
    protected function ensureVisible(Request $request, Vehicle $vehicle): void
    {
        abort_unless($request->user()->canSeeVehicle($vehicle), 404);
    }
}