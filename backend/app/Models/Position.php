<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'vehicle_id',
        'traccar_position_id',
        'latitude',
        'longitude',
        'altitude',
        'speed',
        'course',
        'accuracy',
        'ignition',
        'motion',
        'valid',
        'device_time',
        'server_time',
        'attributes',
        'data_source',
    ];

    protected $casts = [
        'ignition' => 'boolean',
        'motion' => 'boolean',
        'valid' => 'boolean',
        'device_time' => 'datetime',
        'server_time' => 'datetime',
        'attributes' => 'array',
    ];

    public function vehicle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}