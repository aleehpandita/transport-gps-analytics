<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\Geofence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Geocercas que el cliente dibuja en el mapa. Cada empresa solo ve y toca las suyas.
 *
 *   GET    /api/geofences
 *   POST   /api/geofences              {name, shape, latitude, longitude, radius_m | points, color?}
 *   PATCH  /api/geofences/{geofence}   lo mismo, todo opcional
 *   DELETE /api/geofences/{geofence}
 */
class GeofenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $geofences = Geofence::where('company_id', $this->companyId($request))->orderBy('name')->get();

        return response()->json(['data' => $geofences->map->toApi()->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canEdit(), 403, 'Tu usuario es de solo consulta.');

        $data = $this->validated($request, null);
        $geofence = Geofence::create($data + ['company_id' => $this->companyId($request)]);

        return response()->json(['data' => $geofence->toApi()], 201);
    }

    public function update(Request $request, Geofence $geofence): JsonResponse
    {
        $this->authorizeGeofence($request, $geofence);

        $geofence->update($this->validated($request, $geofence));

        return response()->json(['data' => $geofence->fresh()->toApi()]);
    }

    public function destroy(Request $request, Geofence $geofence): JsonResponse
    {
        $this->authorizeGeofence($request, $geofence);

        // No se borra una geocerca que alguna regla vigila: la regla se quedaría sin zona
        $rules = AlertRule::where('company_id', $geofence->company_id)
            ->whereIn('type', [AlertRule::GEOFENCE_EXIT, AlertRule::GEOFENCE_ENTER])
            ->get()
            ->filter(fn (AlertRule $rule) => (int) $rule->setting('geofence_id') === $geofence->id);

        if ($rules->isNotEmpty()) {
            return response()->json([
                'message' => 'La usan estas alertas: ' . $rules->pluck('name')->join(', ') . '. Bórralas o cámbiales la geocerca primero.',
            ], 422);
        }

        $geofence->delete();

        return response()->json(['message' => 'Geocerca eliminada.']);
    }

    protected function validated(Request $request, ?Geofence $current): array
    {
        $creating = $current === null;
        $required = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'shape' => [$required, Rule::in(Geofence::SHAPES)],
            'latitude' => ['required_if:shape,circle', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:shape,circle', 'numeric', 'between:-180,180'],
            'radius_m' => ['required_if:shape,circle', 'nullable', 'integer', 'between:30,50000'],
            'points' => ['required_if:shape,polygon', 'nullable', 'array', 'min:3', 'max:200'],
            'points.*' => ['array', 'size:2'],
            'points.*.0' => ['numeric', 'between:-90,90'],
            'points.*.1' => ['numeric', 'between:-180,180'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'points.required_if' => 'Marca al menos 3 puntos en el mapa.',
            'points.min' => 'Marca al menos 3 puntos en el mapa.',
            'radius_m.between' => 'El radio va de 30 m a 50 km.',
        ]);

        $shape = $data['shape'] ?? $current?->shape;

        if ($shape === Geofence::SHAPE_POLYGON && isset($data['points'])) {
            $points = array_map(fn ($p) => [round((float) $p[0], 7), round((float) $p[1], 7)], $data['points']);
            $data['points'] = $points;
            $data['radius_m'] = null;
            // Centro de los puntos, para centrar el mapa y poner la etiqueta
            $data['latitude'] = array_sum(array_column($points, 0)) / count($points);
            $data['longitude'] = array_sum(array_column($points, 1)) / count($points);
        } elseif ($shape === Geofence::SHAPE_CIRCLE && isset($data['radius_m'])) {
            $data['points'] = null;
        }

        return $data;
    }

    protected function authorizeGeofence(Request $request, Geofence $geofence): void
    {
        abort_unless($geofence->company_id === $this->companyId($request), 404);
        abort_unless($request->user()->canEdit(), 403, 'Tu usuario es de solo consulta.');
    }

    protected function companyId(Request $request): int
    {
        return (int) $request->attributes->get('company_id');
    }
}
