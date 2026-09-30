<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Zone extends Model
{
    //
    protected $fillable = [
        'name',
        'time_from_airport_raw',
        'time_from_airport_minutes',
        'is_airport',
        'requires_ferry_transfer',
        'is_active',
        'region',
        'latitude',
        'longitude',
        'geofence_radius_km',
        'geometry_type',
        'geometry',
        'north_boundary',
        'south_boundary',
        'reference_hotels',
        'notes',
    ];
}
