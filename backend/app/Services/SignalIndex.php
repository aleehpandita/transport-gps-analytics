<?php

namespace App\Services;

use App\Models\TrafficSignal;

/**
 * Índice de semáforos en una cuadrícula de ~110 m, para preguntar rápido "¿hay un semáforo
 * a menos de X metros?" miles de veces sin recorrer todos los semáforos cada vez.
 */
class SignalIndex
{
    // Tamaño de celda en grados: 0.001° ≈ 111 m de latitud
    protected const CELL = 0.001;

    /** @var array<string, list<array{0: float, 1: float}>>|null */
    protected ?array $grid = null;

    public function isNear(float $lat, float $lng, float $meters): bool
    {
        $grid = $this->grid ??= $this->build();

        if ($grid === []) {
            return false;
        }

        // Celdas vecinas que alcanza el radio
        $reach = (int) ceil($meters / 111 / (self::CELL * 1000)) + 1;
        $row = (int) floor($lat / self::CELL);
        $col = (int) floor($lng / self::CELL);

        for ($r = $row - $reach; $r <= $row + $reach; $r++) {
            for ($c = $col - $reach; $c <= $col + $reach; $c++) {
                foreach ($grid["{$r}:{$c}"] ?? [] as [$sLat, $sLng]) {
                    if ($this->meters($lat, $lng, $sLat, $sLng) <= $meters) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, list<array{0: float, 1: float}>>
     */
    protected function build(): array
    {
        $grid = [];

        foreach (TrafficSignal::query()->get(['latitude', 'longitude']) as $signal) {
            $key = (int) floor($signal->latitude / self::CELL) . ':' . (int) floor($signal->longitude / self::CELL);
            $grid[$key][] = [$signal->latitude, $signal->longitude];
        }

        return $grid;
    }

    protected function meters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}