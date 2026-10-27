<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripReview;
use App\Services\OperationClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Confirmar qué fue un viaje desde la página de operación.
 *
 *   POST /api/trip-reviews {trip_id, status, note}
 *
 * status: service, empty, no_show o personal; "clear" borra la confirmación y el viaje
 * vuelve a lo que diga el GPS. Lo puede hacer cualquier usuario sobre sus unidades.
 */
class TripReviewController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trip_id' => ['required', 'integer'],
            'status' => ['required', Rule::in([...OperationClassifier::REVIEWS, 'clear'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $trip = Trip::findOrFail($validated['trip_id']);
        abort_unless($request->user()->visibleVehicles()->whereKey($trip->vehicle_id)->exists(), 404);

        $key = ['vehicle_id' => $trip->vehicle_id, 'trip_started_at' => $trip->started_at];

        if ($validated['status'] === 'clear') {
            TripReview::where($key)->delete();
            return response()->json(['data' => null]);
        }

        $review = TripReview::updateOrCreate($key, [
            'status' => $validated['status'],
            'note' => $validated['note'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => [
            'status' => $review->status,
            'note' => $review->note,
            'by' => $request->user()->name,
            'at' => $review->updated_at->toIso8601String(),
        ]]);
    }
}