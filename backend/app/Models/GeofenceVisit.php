<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una vez que una unidad estuvo dentro de una geocerca: entrada, salida y tiempo dentro.
 * Sin salida (exited_at null) sigue dentro.
 */
class GeofenceVisit extends Model
{
    protected $fillable = [
        'company_id', 'geofence_id', 'vehicle_id', 'entered_at', 'exited_at', 'duration_seconds', 'started_inside',
    ];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'duration_seconds' => 'integer',
            'started_inside' => 'boolean',
        ];
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Tiempo dentro. Si sigue dentro, hasta $now.
     */
    public function secondsInside(?int $now = null): int
    {
        if ($this->exited_at) {
            return (int) $this->duration_seconds;
        }

        return max(0, ($now ?? now()->getTimestamp()) - $this->entered_at->getTimestamp());
    }
}
