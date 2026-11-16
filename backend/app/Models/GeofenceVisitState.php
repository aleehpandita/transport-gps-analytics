<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Memoria del cálculo de entradas y salidas de una unidad: hasta qué posición leyó y de qué
 * lado está de cada geocerca.
 */
class GeofenceVisitState extends Model
{
    protected $primaryKey = 'vehicle_id';
    public $incrementing = false;

    protected $fillable = ['vehicle_id', 'last_device_time', 'state'];

    protected function casts(): array
    {
        return [
            'last_device_time' => 'datetime',
            'state' => 'array',
        ];
    }
}
