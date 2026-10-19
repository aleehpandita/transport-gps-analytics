<?php

namespace App\Http\Resources;

use App\Models\Position;
use App\Services\ZoneMatchingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class VehicleResource extends JsonResource
{
    /**
     * Velocidad (km/h) por debajo de la cual la unidad se considera detenida.
     * Mismo valor que usa TripBuilderService: el GPS reporta 1 o 2 km/h de ruido estando parada.
     */
    protected const IDLE_SPEED_KMH = 3;

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
            'latest_position' => $this->whenLoaded(
                'latestPosition',
                fn () => $this->latestPosition ? new PositionResource($this->latestPosition) : null,
            ),
            'current_zone' => $this->whenLoaded('latestPosition', fn () => $this->currentZone()),
            'stopped_since' => $this->whenLoaded('latestPosition', fn () => $this->stoppedSince()),
        ];
    }

    /**
     * Zona donde está la unidad según su última posición, con la misma prioridad que los
     * viajes: punto, luego polígono, luego área de destino. null si no cae en ninguna.
     */
    protected function currentZone(): ?array
    {
        $position = $this->latestPosition;

        if (!$position) {
            return null;
        }

        $zone = app(ZoneMatchingService::class)->resolve($position->latitude, $position->longitude);

        return $zone ? ['id' => $zone->id, 'name' => $zone->name] : null;
    }

    /**
     * Desde cuándo está detenida la unidad: la primera posición después de la última
     * lectura en movimiento. null si la última posición va en movimiento.
     *
     * No usa la ignición a propósito: en Kevs1 la señal parpadeaba por el umbral de voltaje
     * (ver docs/piloto-fmc920.md), así que se basa solo en velocidad y tiempo.
     */
    protected function stoppedSince(): ?string
    {
        $position = $this->latestPosition;

        if (!$position || $position->speed >= self::IDLE_SPEED_KMH) {
            return null;
        }

        $positions = Position::where('vehicle_id', $this->id)
            ->where('valid', true)
            ->where('device_time', '<=', $position->device_time);

        $lastMovingAt = (clone $positions)
            ->where('speed', '>=', self::IDLE_SPEED_KMH)
            ->orderByDesc('device_time')
            ->value('device_time');

        // Si nunca se ha movido en los datos que tenemos, la parada empieza en la primera posición.
        $stoppedAt = (clone $positions)
            ->when($lastMovingAt, fn ($query) => $query->where('device_time', '>', $lastMovingAt))
            ->orderBy('device_time')
            ->value('device_time');

        // value() regresa el texto crudo de la base, sin el cast del modelo. Se guarda en UTC.
        return $stoppedAt ? Carbon::parse($stoppedAt, 'UTC')->toIso8601ZuluString() : null;
    }
}