<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una orden de servicio (ver la migración create_service_orders_table).
 */
class ServiceOrder extends Model
{
    public const TYPES = ['llegada', 'salida', 'traslado'];

    protected $fillable = [
        'vehicle_id', 'service_date', 'scheduled_time', 'type', 'place_id', 'place_text',
        'terminal', 'pax', 'source', 'notes',
    ];

    protected function casts(): array
    {
        return ['service_date' => 'date:Y-m-d'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}