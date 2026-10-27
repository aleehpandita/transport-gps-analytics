<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Services\ZoneMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Alta de lugares desde el panel (por ahora, aprobar un punto que la operación detectó).
 * Solo administradores: el catálogo es de toda la flota.
 *
 *   POST /api/places {name, latitude, longitude, type, radius_m}
 */
class PlaceController extends Controller
{
    public function store(Request $request, ZoneMatchingService $zones): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Solo un administrador puede dar de alta lugares.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'type' => ['nullable', Rule::in(Place::TYPES)],
            'radius_m' => ['nullable', 'integer', 'min:20', 'max:1000'],
        ]);

        $zone = $zones->resolve((float) $validated['latitude'], (float) $validated['longitude']);

        // Igual que places:add: solo entre los manuales; si el nombre ya existe, se actualiza
        $place = Place::updateOrCreate(
            ['name' => $validated['name'], 'source' => Place::SOURCE_MANUAL],
            [
                'type' => $validated['type'] ?? 'hotel',
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'radius_m' => $validated['radius_m'] ?? 60,
                'zone_id' => $zone?->id,
                'is_active' => true,
                'notes' => 'Aprobado desde la operación del día',
            ]
        );

        return response()->json(['data' => [
            'id' => $place->id,
            'name' => $place->name,
            'type' => $place->type,
            'zone' => $zone?->name,
        ]], $place->wasRecentlyCreated ? 201 : 200);
    }
}