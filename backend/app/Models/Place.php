<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Place extends Model
{
    public const TYPES = ['airport_terminal', 'hotel', 'other'];

    protected $fillable = [
        'name',
        'type',
        'latitude',
        'longitude',
        'radius_m',
        'zone_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_m' => 'integer',
        'is_active' => 'boolean',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}