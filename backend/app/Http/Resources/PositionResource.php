<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'speed' => $this->speed,
            'course' => $this->course,
            'ignition' => $this->ignition,
            'motion' => $this->motion,
            'valid' => $this->valid,
            'device_time' => $this->device_time->toIso8601String(),
            'data_source' => $this->data_source,
        ];
    }
}