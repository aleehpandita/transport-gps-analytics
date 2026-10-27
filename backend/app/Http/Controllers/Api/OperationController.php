<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operación del día armada con GPS.
 *
 *   GET /api/operations?date=2026-10-25&vehicle_id=2
 */
class OperationController extends Controller
{
    public function index(Request $request, OperationService $operations): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'vehicle_id' => ['nullable', 'integer'],
        ]);

        $vehicles = $request->user()->visibleVehicles()
            ->when(isset($validated['vehicle_id']), fn ($q) => $q->where('id', $validated['vehicle_id']))
            ->pluck('name', 'id');

        return response()->json(['data' => $operations->forDay($validated['date'], $vehicles)]);
    }
}