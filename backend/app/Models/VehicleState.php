<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Memoria del detector de eventos para una unidad: hasta dónde leyó y qué está en curso.
 */
class VehicleState extends Model
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