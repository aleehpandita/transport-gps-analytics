<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    //
    protected $fillable = [
        'name',
        'plate',
        'imei',
        'traccar_device_id',
        'make',
        'model',
        'active',
    ];
}
