<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    protected $fillable = [
        'vehicle_id',
        'origin_zone_id',
        'destination_zone_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'distance_km',
        'average_speed',
        'max_speed',
        'stops_count',
        'stopped_seconds',
        'data_source',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function vehicle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}