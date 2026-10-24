<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Algo que le pasó a una unidad: entrar a una zona, llegar a un lobby, ir rápido...
 * Los que duran tienen started_at y ended_at; mientras siguen pasando, ended_at es null.
 */
class VehicleEvent extends Model
{
    public const ZONE_ENTER = 'zone_enter';
    public const ZONE_EXIT = 'zone_exit';
    // Llegada y salida de un lugar del catálogo en un solo evento: started_at es la llegada
    // y ended_at la salida. Es la base de la constancia de presencia.
    public const PLACE_VISIT = 'place_visit';
    public const OVERSPEED = 'overspeed';
    public const LONG_STOP = 'long_stop';
    public const SIGNAL_LOST = 'signal_lost';

    public const TYPES = [
        self::ZONE_ENTER,
        self::ZONE_EXIT,
        self::PLACE_VISIT,
        self::OVERSPEED,
        self::LONG_STOP,
        self::SIGNAL_LOST,
    ];

    // Los que merecen atención (los demás son bitácora de la operación)
    public const ALERTS = [self::OVERSPEED, self::LONG_STOP, self::SIGNAL_LOST];

    protected $fillable = [
        'vehicle_id', 'type', 'started_at', 'ended_at', 'latitude', 'longitude',
        'zone_id', 'place_id', 'value', 'data',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'value' => 'float',
            'data' => 'array',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}