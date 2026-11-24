<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'plate',
        'imei',
        'traccar_device_id',
        'make',
        'model',
        'icon',
        'color',
        'active',
    ];

    // Cómo se dibuja en el mapa (vista desde arriba). Lo elige el cliente.
    public const ICON_VAN = 'van';
    public const ICON_SEDAN = 'sedan';
    public const ICONS = [self::ICON_VAN, self::ICON_SEDAN];

    // Color en formato #rrggbb
    public const COLOR_RULE = 'regex:/^#[0-9a-fA-F]{6}$/';

    protected $casts = [
        'active' => 'boolean',
    ];

    // Placas siempre en mayúsculas y sin espacios a los lados; vacías = null
    public function setPlateAttribute(?string $value): void
    {
        $value = $value === null ? '' : strtoupper(trim($value));
        $this->attributes['plate'] = $value === '' ? null : $value;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

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