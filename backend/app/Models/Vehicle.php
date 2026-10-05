<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    protected $fillable = [
        'name',
        'plate',
        'imei',
        'traccar_device_id',
        'make',
        'model',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    /**
     * Última posición conocida del vehículo, según la hora del dispositivo.
     * Se resuelve con una sola subconsulta aunque se cargue para toda la flota.
     */
    public function latestPosition(): HasOne
    {
        return $this->hasOne(Position::class)->latestOfMany('device_time');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }
}