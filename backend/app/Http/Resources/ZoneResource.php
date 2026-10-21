<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'region' => $this->region,
            'is_airport' => $this->is_airport,
            'requires_ferry_transfer' => $this->requires_ferry_transfer,
            'time_from_airport_minutes' => $this->time_from_airport_minutes,

            // Para dibujar la zona en el mapa. Las zonas por punto usan centro y radio;
            // las de polígono traen la geometría GeoJSON que importó zones:import-geometry.
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'geofence_radius_km' => $this->geofence_radius_km,
            'geometry_type' => $this->geometry_type,
            'geometry' => in_array($this->geometry_type, ['Polygon', 'MultiPolygon'], true) ? $this->geometry : null,
        ];
    }
}