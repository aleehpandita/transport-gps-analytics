<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle' => $this->vehicle?->name,
            'vehicle_id' => $this->vehicle_id,
            'destination_zone' => $this->destinationZone?->name,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}
