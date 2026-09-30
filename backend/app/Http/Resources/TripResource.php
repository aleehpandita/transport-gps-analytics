<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->started_at->toIso8601String(),
            'ended_at' => $this->ended_at->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'distance_km' => $this->distance_km,
            'average_speed' => $this->average_speed,
            'max_speed' => $this->max_speed,
            'stops_count' => $this->stops_count,
            'stopped_seconds' => $this->stopped_seconds,
            'origin_zone_id' => $this->origin_zone_id,
            'destination_zone_id' => $this->destination_zone_id,
            'data_source' => $this->data_source,
        ];
    }
}