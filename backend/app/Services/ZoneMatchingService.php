<?php

namespace App\Services;

use App\Models\Zone;

class ZoneMatchingService
{
    /**
     * Resuelve a qué zona corresponde una coordenada.
     * Orden de prioridad: primero zonas tipo Point (muelles, destinos puntuales,
     * más precisas y pequeñas), luego Polygon (corredores), al final
     * DestinationArea como fallback amplio (radio grande, sin geometría fina).
     */
    public function resolve(float $lat, float $lon): ?Zone
    {
        $zones = Zone::where('is_active', true)->get();

        foreach ($zones->whereIn('geometry_type', ['Point']) as $zone) {
            if ($zone->latitude && $this->withinRadius($lat, $lon, $zone->latitude, $zone->longitude, $zone->geofence_radius_km ?? 0.5)) {
                return $zone;
            }
        }

        foreach ($zones->whereIn('geometry_type', ['Polygon', 'MultiPolygon']) as $zone) {
            if ($zone->geometry && $this->pointInPolygon($lat, $lon, $zone->geometry)) {
                return $zone;
            }
        }

        foreach ($zones->where('geometry_type', 'DestinationArea') as $zone) {
            if ($zone->latitude && $this->withinRadius($lat, $lon, $zone->latitude, $zone->longitude, $zone->geofence_radius_km ?? 15)) {
                return $zone;
            }
        }

        return null;
    }

    protected function withinRadius(float $lat, float $lon, float $centerLat, float $centerLon, float $radiusKm): bool
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($centerLat - $lat);
        $dLon = deg2rad($centerLon - $lon);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($centerLat)) * sin($dLon / 2) ** 2;
        $distance = $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $distance <= $radiusKm;
    }

    /**
     * Ray casting: cuenta cruces de un rayo horizontal desde el punto hacia
     * la derecha; número impar de cruces = está dentro del polígono.
     */
    protected function pointInPolygon(float $lat, float $lon, array $geoJson): bool
    {
        $rings = $geoJson['type'] === 'MultiPolygon'
            ? array_merge(...$geoJson['coordinates'])
            : $geoJson['coordinates'];

        $polygon = $rings[0]; // anillo exterior, sin manejar huecos por ahora
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $polygon[$i]; // GeoJSON: [lon, lat]
            [$xj, $yj] = $polygon[$j];

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}