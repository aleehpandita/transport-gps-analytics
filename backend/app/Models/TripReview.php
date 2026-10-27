<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Confirmación de una persona sobre un viaje (ver la migración create_trip_reviews_table).
 */
class TripReview extends Model
{
    protected $fillable = ['vehicle_id', 'trip_started_at', 'status', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['trip_started_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}