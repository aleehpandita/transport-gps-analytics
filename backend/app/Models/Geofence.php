<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zona que dibuja el cliente en el mapa: un círculo (centro y radio) o un polígono.
 */
class Geofence extends Model
{
    public const SHAPE_CIRCLE = 'circle';
    public const SHAPE_POLYGON = 'polygon';
    public const SHAPES = [self::SHAPE_CIRCLE, self::SHAPE_POLYGON];

    protected $fillable = ['company_id', 'name', 'shape', 'latitude', 'longitude', 'radius_m', 'points', 'color'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'integer',
            'points' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * ¿El punto está dentro? Círculo: distancia al centro. Polígono: rayo (lng = x, lat = y),
     * que a la escala de una ciudad no necesita proyección.
     */
    public function contains(float $lat, float $lng): bool
    {
        if ($this->shape === self::SHAPE_CIRCLE) {
            return self::meters($lat, $lng, $this->latitude, $this->longitude) <= $this->radius_m;
        }

        $points = $this->points ?? [];
        $inside = false;
        $n = count($points);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = [(float) $points[$i][0], (float) $points[$i][1]];
            [$yj, $xj] = [(float) $points[$j][0], (float) $points[$j][1]];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shape' => $this->shape,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius_m' => $this->radius_m,
            'points' => $this->points,
            'color' => $this->color,
        ];
    }
}
