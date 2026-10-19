<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\TraccarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Posición en vivo de cada unidad, leída directo de Traccar.
 *
 * El sync de cada 2 minutos (traccar:sync-positions) sigue siendo la fuente para viajes,
 * zonas y el modelo. Este endpoint solo alimenta el mapa: no guarda nada en la base.
 */
class LiveVehicleController extends Controller
{
    // Traccar regresa la velocidad en nudos, igual que en traccar:sync-positions
    protected const KNOTS_TO_KMH = 1.852;

    // Si hay varias pestañas abiertas, Traccar recibe una sola consulta cada 3 segundos
    protected const CACHE_SECONDS = 3;

    public function index(TraccarService $traccar): JsonResponse
    {
        try {
            $data = Cache::remember('vehicles:live', now()->addSeconds(self::CACHE_SECONDS), function () use ($traccar) {
                // traccar_device_id => id de la unidad en nuestra base
                $vehicleIds = Vehicle::whereNotNull('traccar_device_id')->pluck('id', 'traccar_device_id');

                return collect($traccar->getLatestPositions())
                    ->filter(fn (array $p) => isset($vehicleIds[$p['deviceId']]))
                    ->map(fn (array $p) => [
                        'vehicle_id' => $vehicleIds[$p['deviceId']],
                        'latitude' => $p['latitude'],
                        'longitude' => $p['longitude'],
                        'speed' => round(($p['speed'] ?? 0) * self::KNOTS_TO_KMH, 1),
                        'course' => $p['course'] ?? 0,
                        'ignition' => $p['attributes']['ignition'] ?? null,
                        'valid' => (bool) ($p['valid'] ?? false),
                        'device_time' => Carbon::parse($p['deviceTime'])->utc()->toIso8601String(),
                    ])
                    ->values()
                    ->all();
            });
        } catch (\RuntimeException $e) {
            // Traccar no respondió: el mapa conserva lo último que tenía y vuelve a intentar
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $data]);
    }
}