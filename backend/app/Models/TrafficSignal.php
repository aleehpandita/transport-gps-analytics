<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficSignal extends Model
{
    protected $fillable = ['osm_id', 'latitude', 'longitude'];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];
}