<?php

namespace App\Services;

use App\Models\Zone;

class ZoneMatchingService
{
    /**
     * Resuelve a qué zona corresponde una coordenada.
     * Orden de prioridad: primero zonas tipo Point (muelles, destinos puntuales,
     * más precisas y pequeñas), luego Polygon/MultiPolygon (corredores y ciudades),
     * al final DestinationArea como fallback amplio (radio grande, sin geometría fina).
     * Ver docs/zonificacion-maestra.md.
     */
    public function resolve(float $lat, float $lon): ?Zone
    {
        $zones = Zone::where('is_active', true)->get();

        foreach ($zones->where('geometry_type', 'Point') as $zone) {
            if ($zone->latitude && $this->withinRadius($lat, $lon, $zone->latitude, $zone->longitude, $zone->geofence_radius_km ?? 0.5)) {
                return $zone;
            }
        }

        foreach ($zones->whereIn('geometry_type', ['Polygon', 'MultiPolygon']) as $zone) {
            if ($zone->geometry && $this->containsPoint($lat, $lon, $zone->geometry)) {
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
     * Point-in-polygon para geometrías GeoJSON.
     *
     * - Polygon: coordinates = [anillo exterior, hueco 1, hueco 2, ...]
     * - MultiPolygon: coordinates = [polígono 1, polígono 2, ...], cada uno con la forma de arriba
     *
     * El punto está dentro si cae en el anillo exterior de alguna de las piezas
     * y no cae en ninguno de los huecos de esa pieza.
     */
    protected function containsPoint(float $lat, float $lon, array $geoJson): bool
    {
        $polygons = match ($geoJson['type'] ?? null) {
            'Polygon' => [$geoJson['coordinates']],
            'MultiPolygon' => $geoJson['coordinates'],
            default => [],
        };

        foreach ($polygons as $rings) {
            $outer = array_shift($rings);

            if (!$outer || !$this->pointInRing($lat, $lon, $outer)) {
                continue;
            }

            foreach ($rings as $hole) {
                if ($this->pointInRing($lat, $lon, $hole)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Ray casting: cuenta cruces de un rayo horizontal desde el punto hacia
     * la derecha; número impar de cruces = está dentro del anillo.
     */
    protected function pointInRing(float $lat, float $lon, array $ring): bool
    {
        $inside = false;
        $n = count($ring);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i]; // GeoJSON: [lon, lat]
            [$xj, $yj] = $ring[$j];

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}