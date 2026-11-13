<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Memoria del motor de alertas para una unidad: hasta qué posición leyó y, por cada regla,
 * lo que está en curso (dentro o fuera de una geocerca, una parada, un exceso...).
 */
class AlertState extends Model
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
