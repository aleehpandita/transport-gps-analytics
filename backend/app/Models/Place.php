<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Place extends Model
{
    // hotel y stop (punto de pasajeros: estación, muelle, residencial, casa) son donde se sube
    // o baja gente. base: donde se guarda la unidad o para el chofer (no es parada larga y sus
    // viajes van vacíos). other: gasolinera, espera de choferes y lo demás (viajes vacíos).
    public const TYPES = ['airport_terminal', 'hotel', 'stop', 'base', 'other'];

    // De dónde viene el lugar (ver la migración add_source_to_places_table)
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_OSM = 'osm';
    public const SOURCE_LEARNED = 'learned';

    protected $fillable = [
        'name',
        'type',
        'source',
        'external_id',
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