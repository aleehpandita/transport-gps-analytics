<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VehicleController extends Controller
{
   public function index(): AnonymousResourceCollection
    {
        return VehicleResource::collection(
            Vehicle::where('active', true)
                ->withSum(['trips as distance_today_km' => function ($query) {
                    $query->whereDate('started_at', today())->where('data_source', 'real');
                }], 'distance_km')
                ->get()
        );
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        $vehicle->loadSum(['trips as distance_today_km' => function ($query) {
            $query->whereDate('started_at', today())->where('data_source', 'real');
        }], 'distance_km');

        return new VehicleResource($vehicle);
    }

    public function latestPosition(Vehicle $vehicle): PositionResource|\Illuminate\Http\JsonResponse
    {
        $position = $vehicle->positions()->orderByDesc('device_time')->first();

        if (!$position) {
            return response()->json(['message' => 'Sin posiciones registradas para este vehículo.'], 404);
        }

        return new PositionResource($position);
    }

    public function positions(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
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
        $query = $vehicle->trips()->orderByDesc('started_at');

        if ($request->filled('data_source')) {
            $query->where('data_source', $request->string('data_source'));
        }

        return TripResource::collection($query->paginate(50));
    }
}