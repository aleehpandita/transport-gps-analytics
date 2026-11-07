<?php

namespace App\Services;

/**
 * Compara las órdenes de servicio de un día contra los servicios que detectó el GPS.
 *
 * Cada orden se empareja con a lo más un servicio de la misma unidad y del mismo tipo (o
 * de cualquier tipo si la orden es 'auto': la hoja no lo dice y lo deduce el GPS),
 * dentro de WINDOW_MINUTES de la hora de la orden. La hora que se compara es cuando la
 * unidad arrancó con los pasajeros: en una llegada, cuando salió del aeropuerto; en una
 * salida, cuando salió del hotel. Medido el 2 nov: entre 1 y 8 min de diferencia.
 * Se prefiere el que coincide en lugar y, después, el más cercano en hora.
 *
 * Resultado por orden:
 *   - ok: tipo, hora y lugar correctos
 *   - sin_nombre: tipo y hora correctos, pero el GPS paró en un punto sin nombre
 *   - otro_lugar: tipo y hora correctos, en otro lugar del catálogo
 *   - otra_hora: mismo tipo y lugar, pero lejos de la hora de la hoja (hasta
 *     LATE_WINDOW_MINUTES). 3 nov: la hoja decía Moon Palace 17:00 y el GPS recogió a las
 *     19:15. Es una diferencia entre la hoja y lo que pasó, para revisar con el chofer.
 *   - no_detectado: ningún servicio la explica
 * Y los servicios que no explica ninguna orden: inventados por el GPS.
 *
 * Servicios alternativos ('alt' => true): regresos vacíos desde un hotel que, según la
 * hoja, pudieron ser la salida. El GPS no distingue una recogida rápida de una dejada
 * (Le Blanc 27 oct, Temptation 3 nov, Oasis Palm 4 nov), pero la hora de la hoja sí:
 * en una salida coincide con cuando la unidad dejó el hotel, no con cuando dejó el
 * aeropuerto. Solo cuentan si una orden los explica; si no, no son inventados.
 *
 * Trabaja sobre arreglos simples para poder probarlo sin base de datos.
 */
class OrderMatcher
{
    public const OK = 'ok';
    public const UNNAMED = 'sin_nombre';
    public const OTHER_PLACE = 'otro_lugar';
    public const OTHER_TIME = 'otra_hora';
    public const MISSED = 'no_detectado';

    protected const WINDOW_MINUTES = 75;
    protected const LATE_WINDOW_MINUTES = 240;
    // Del aeropuerto al hotel para recoger: la "llegada" previa a una salida deducida
    protected const PICKUP_RUN_MINUTES = 120;
    // Un servicio en un punto a esta distancia del lugar de la orden es el mismo lugar
    // (un lobby dado de alta dos veces, la entrada de un complejo)
    protected const SAME_PLACE_METERS = 300;

    public function __construct(protected PlaceNameMatcher $names = new PlaceNameMatcher())
    {
    }

    /**
     * @param list<array{id: int, vehicle_id: int, time: int, type: string, place_id: ?int,
     *   place_text: string, place_lat: ?float, place_lng: ?float}> $orders time en timestamp
     * @param list<array{trip_id: int, vehicle_id: int, type: string, left_at: int,
     *   place_id: ?int, hotel: ?string, hotel_latitude: ?float, hotel_longitude: ?float,
     *   status: string}> $services left_at en timestamp
     * @return array{orders: list<array>, extra: list<array>}
     */
    public function match(array $orders, array $services): array
    {
        // Todas las parejas posibles, de la mejor a la peor
        $pairs = [];
        foreach ($orders as $o => $order) {
            foreach ($services as $s => $service) {
                if ($service['vehicle_id'] !== $order['vehicle_id']
                    || ($order['type'] !== 'auto' && $service['type'] !== $order['type'])) {
                    continue;
                }
                $minutes = abs($service['left_at'] - $order['time']) / 60;
                if ($minutes > self::LATE_WINDOW_MINUTES) {
                    continue;
                }
                $result = $this->placeResult($order, $service);
                if ($minutes > self::WINDOW_MINUTES) {
                    // Lejos de la hora: solo cuenta si es el mismo lugar
                    if ($result !== self::OK) {
                        continue;
                    }
                    $result = self::OTHER_TIME;
                }
                $rank = [self::OK => 0, self::UNNAMED => 1, self::OTHER_PLACE => 2, self::OTHER_TIME => 3][$result];
                $pairs[] = [$rank, $minutes, $o, $s, $result];
            }
        }
        usort($pairs, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $usedOrders = [];
        $usedServices = [];
        $results = [];
        foreach ($pairs as [, $minutes, $o, $s, $result]) {
            if (isset($usedOrders[$o]) || isset($usedServices[$s])) {
                continue;
            }
            $usedOrders[$o] = $usedServices[$s] = true;
            $results[$o] = $orders[$o] + [
                'result' => $result,
                'service' => $services[$s],
                'minutes' => (int) round(($services[$s]['left_at'] - $orders[$o]['time']) / 60),
            ];
        }

        foreach ($orders as $o => $order) {
            $results[$o] ??= $order + ['result' => self::MISSED, 'service' => null, 'minutes' => null];
        }
        ksort($results);

        // Si la hoja convirtió un regreso en salida, la "llegada" al mismo hotel justo antes
        // fue el viaje vacío para recoger: no es un servicio inventado
        $positioning = [];
        foreach ($services as $s => $service) {
            if (empty($service['alt']) || !isset($usedServices[$s])) {
                continue;
            }
            foreach ($services as $p => $before) {
                if ($before['type'] === 'llegada' && empty($before['alt'])
                    && $before['vehicle_id'] === $service['vehicle_id']
                    && $before['left_at'] < $service['left_at']
                    && $service['left_at'] - $before['left_at'] <= self::PICKUP_RUN_MINUTES * 60
                    && $before['hotel'] === $service['hotel']) {
                    $positioning[$p] = true;
                }
            }
        }

        $extra = [];
        foreach ($services as $s => $service) {
            if (!isset($usedServices[$s]) && empty($service['alt']) && !isset($positioning[$s])) {
                $extra[] = $service;
            }
        }

        return ['orders' => array_values($results), 'extra' => $extra];
    }

    protected function placeResult(array $order, array $service): string
    {
        if ($order['place_id'] !== null && $service['place_id'] === $order['place_id']) {
            return self::OK;
        }

        if ($order['place_lat'] !== null && $service['hotel_latitude'] !== null
            && $this->meters($order['place_lat'], $order['place_lng'], $service['hotel_latitude'], $service['hotel_longitude']) <= self::SAME_PLACE_METERS) {
            return self::OK;
        }

        // Mismo nombre aunque sea otra entrada del complejo: "Moon Palace" de la hoja y
        // "Moon Palace Nizuc lobby" donde se detuvo la unidad
        if ($service['hotel'] !== null
            && $this->names->match($order['place_text'], [['id' => 1, 'name' => $service['hotel']]]) === 1) {
            return self::OK;
        }

        // El otro hotel donde se detuvo justo antes de la salida (ver OperationClassifier)
        if (($service['other_hotel'] ?? null) !== null
            && $this->names->match($order['place_text'], [['id' => 1, 'name' => $service['other_hotel']]]) === 1) {
            return self::OK;
        }

        return $service['hotel'] === null ? self::UNNAMED : self::OTHER_PLACE;
    }

    protected function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($x)));
    }
}