<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledService extends Model
{
    protected $fillable = ['vehicle_id', 'destination_zone_id', 'scheduled_at', 'notes'];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function vehicle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function destinationZone(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Zone::class, 'destination_zone_id');
    }
}