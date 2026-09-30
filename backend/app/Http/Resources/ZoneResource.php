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
        ];
    }
}