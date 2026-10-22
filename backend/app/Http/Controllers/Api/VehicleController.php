<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
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