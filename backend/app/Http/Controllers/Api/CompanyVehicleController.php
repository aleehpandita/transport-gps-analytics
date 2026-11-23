<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El dueño de una empresa arregla sus unidades: nombre, placas, ícono y color en el mapa.
 * El IMEI y la empresa solo los cambia el equipo de EkMira (VehicleAdminController).
 *
 *   GET   /api/company/vehicles
 *   PATCH /api/company/vehicles/{vehicle}   {name?, plate?, icon?, color?}
 */
class CompanyVehicleController extends Controller
{
    public function __construct(protected AccountService $accounts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $vehicles = Vehicle::with('latestPosition')
            ->where('company_id', $request->user()->company_id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $vehicles->map(fn ($v) => $this->accounts->vehicleRow($v))->values()]);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        // Una unidad de otra empresa no existe para este dueño
        abort_unless($vehicle->company_id === $request->user()->company_id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'plate' => ['sometimes', 'nullable', 'string', 'max:20'],
            'icon' => ['sometimes', Rule::in(Vehicle::ICONS)],
            'color' => ['sometimes', 'nullable', Vehicle::COLOR_RULE],
        ]);

        $vehicle->update($validated);

        return response()->json(['data' => $this->accounts->vehicleRow($vehicle->load('latestPosition'))]);
    }
}
