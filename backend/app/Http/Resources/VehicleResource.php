<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'plate' => $this->plate,
            'make' => $this->make,
            'model' => $this->model,
            'active' => $this->active,
            'distance_today_km' => round($this->distance_today_km ?? 0, 2),
        ];
    }
}