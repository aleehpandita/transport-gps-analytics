<?php

namespace App\Services;

/**
 * Arma la operación del día solo con GPS: decide qué viaje fue una llegada (aeropuerto →
 * hotel con pasajeros), una salida (hotel → aeropuerto con pasajeros) o un movimiento
 * vacío (regreso, posicionamiento, maniobra).
 *
 * No necesita la orden de servicio. Las reglas salen de comparar el GPS contra las
 * órdenes de Kevs1 del 23 y del 25 de octubre (15 servicios):
 *
 *   - Llegada: sale del aeropuerto y en el destino se queda poco (deja a los pasajeros y
 *     se va). Si se queda LONG_WAIT_MINUTES o más, fue a esperar una recogida: es un
 *     posicionamiento vacío (25 oct: aeropuerto → Playacar, 28 min antes de recoger en
 *     Royal Hideaway).
 *   - Salida: llega al aeropuerto desde un hotel. Si antes acaba de dejar pasajeros (una
 *     llegada hace menos de RETURN_WINDOW_MINUTES, sin otro viaje de por medio salvo
 *     maniobras), es el regreso vacío, no una salida. 26 oct: dejó gente en Platinum
 *     Yucatán Princess, se movió 600 m dentro del complejo y regresó al aeropuerto.
 *
 * El GPS no sabe si alguien se subió. Por eso cada servicio sale como "probable" o "por
 * confirmar" (con los motivos), y quien opera lo confirma: servicio, sin pasajeros,
 * no-show o uso personal. Mientras nadie confirme, lo dudoso NO cuenta como km con
 * pasajeros. Lo confirmado manda sobre lo que diga el GPS.
 *   - Desde o hacia un lugar conocido que no es hotel (la base, la gasolinera) va vacío.
 *   - Lo demás (hotel → hotel, maniobras de menos de MIN_SERVICE_KM) va vacío.
 *
 * Trabaja sobre arreglos simples para poder probarlo sin base de datos.
 */
class OperationClassifier
{
    public const ARRIVAL = 'llegada';
    public const DEPARTURE = 'salida';
    public const RETURN = 'regreso';
    public const POSITIONING = 'posicionamiento';
    public const MANEUVER = 'maniobra';

    public const WITH_PASSENGERS = [self::ARRIVAL, self::DEPARTURE];

    // Estado de cada servicio
    public const PROBABLE = 'probable';
    public const PENDING = 'por_confirmar';
    public const CONFIRMED = 'confirmado';

    // Lo que puede marcar quien opera (trip_reviews.status)
    public const REVIEW_SERVICE = 'service';
    public const REVIEW_EMPTY = 'empty';
    public const REVIEW_NO_SHOW = 'no_show';
    public const REVIEW_PERSONAL = 'personal';
    public const REVIEWS = [self::REVIEW_SERVICE, self::REVIEW_EMPTY, self::REVIEW_NO_SHOW, self::REVIEW_PERSONAL];

    // Menos que esto no es un servicio (vueltas entre terminales, acomodarse en el lobby)
    protected const MIN_SERVICE_KM = 2;
    // Tiempo en el destino a partir del cual ya no fue una bajada sino una espera
    protected const LONG_WAIT_MINUTES = 15;
    // El aeropuerto es todo lo que esté a esta distancia de una terminal (incluye el punto
    // de encuentro de llegadas y la espera de transportistas)
    protected const AIRPORT_METERS = 1500;
    // Para ligar el fin de un viaje con el inicio del siguiente
    protected const SAME_SPOT_METERS = 300;
    // Después de una bajada, un viaje al aeropuerto dentro de este tiempo es el regreso
    protected const RETURN_WINDOW_MINUTES = 30;
    // Una salida con menos espera que esto es dudosa (26 oct, 09:11: 2 min junto a un hotel
    // del aeropuerto, sin servicio en la orden)
    protected const MIN_PICKUP_WAIT_SECONDS = 180;
    // Si sale del aeropuerto hacia un hotel menos de esto después de dejar pasajeros de una
    // salida, desde el mismo punto, puede ser una llegada (los pasajeros ya esperaban) o que
    // vaya por otra salida (27 oct: dejó en T4 y 2 min después fue a recoger a Le Blanc).
    // Con 3 min o más, en las órdenes del 22 al 26 oct siempre fue una llegada.
    protected const QUICK_TURN_SECONDS = 180;

    /**
     * @param list<array{lat: float, lng: float}> $terminals Terminales del aeropuerto
     */
    public function __construct(protected array $terminals)
    {
    }

    /**
     * @param list<array{
     *   id: int, start: int, end: int, km: float,
     *   origin: array{lat: float, lng: float, name: ?string, type: ?string},
     *   destination: array{lat: float, lng: float, name: ?string, type: ?string}
     * }> $legs Viajes de una unidad, en orden. start y end en timestamp.
     * @return list<array> Los mismos viajes con 'kind', y los datos de servicio en los que llevan pasajeros
     */
    public function classify(array $legs): array
    {
        $out = [];
        $n = count($legs);

        for ($i = 0; $i < $n; $i++) {
            $leg = $legs[$i];
            $leg['review'] ??= null;
            $prev = $out[$i - 1] ?? null;
            $next = $legs[$i + 1] ?? null;

            $leg['kind'] = $this->kind($leg, $out, $next);
            $leg['doubt'] = $this->doubt($leg, $out);

            // Datos del servicio
            if ($leg['kind'] === self::ARRIVAL) {
                $leg['service'] = [
                    'type' => self::ARRIVAL,
                    'terminal' => $leg['origin']['name'],
                    'hotel' => $leg['destination']['name'],
                    'hotel_point' => [$leg['destination']['lat'], $leg['destination']['lng']],
                    'left_at' => $leg['start'],      // salió del aeropuerto con los pasajeros
                    'arrived_at' => $leg['end'],     // llegó al hotel
                    'stay_seconds' => $next ? $next['start'] - $leg['end'] : null,
                ];
            } elseif ($leg['kind'] === self::DEPARTURE) {
                $waitedHere = $prev && $this->meters($prev['destination'], $leg['origin']) <= self::SAME_SPOT_METERS;
                $leg['service'] = [
                    'type' => self::DEPARTURE,
                    'hotel' => $leg['origin']['name'],
                    'hotel_point' => [$leg['origin']['lat'], $leg['origin']['lng']],
                    'terminal' => $leg['destination']['name'],
                    'reached_hotel_at' => $waitedHere ? $prev['end'] : null, // llegó por los pasajeros
                    'left_at' => $leg['start'],
                    'arrived_at' => $leg['end'],     // llegó al aeropuerto
                    'wait_seconds' => $waitedHere ? $leg['start'] - $prev['end'] : null,
                ];
            }

            [$leg['status'], $leg['reasons'], $leg['with_passengers']] = $this->status($leg);
            $out[] = $leg;
        }

        return $out;
    }

    /**
     * @param list<array> $before Viajes anteriores ya clasificados
     */
    protected function kind(array $leg, array $before, ?array $next): string
    {
        $fromAirport = $this->isAirport($leg['origin']);
        $toAirport = $this->isAirport($leg['destination']);

        if ($leg['km'] < self::MIN_SERVICE_KM || ($fromAirport && $toAirport)) {
            return self::MANEUVER;
        }

        if ($fromAirport) {
            if ($this->isNotPickupSpot($leg['destination'])) {
                return self::POSITIONING;
            }
            $stay = $next ? $next['start'] - $leg['end'] : 0;

            return $stay < self::LONG_WAIT_MINUTES * 60 ? self::ARRIVAL : self::POSITIONING;
        }

        if ($toAirport) {
            if ($this->isNotPickupSpot($leg['origin'])) {
                return self::POSITIONING;
            }
            return $this->isReturn($leg, $before) ? self::RETURN : self::DEPARTURE;
        }

        return self::POSITIONING;
    }

    /**
     * true si es el regreso vacío después de dejar pasajeros: hubo una llegada hace menos de
     * RETURN_WINDOW_MINUTES y, desde entonces, solo maniobras o movimientos vacíos, sin una
     * espera de recogida en el punto de donde sale. 27 oct: dejó en Viva Wyndham Maya pasando
     * por la caseta de Playacar (3.7 km) y regresó al aeropuerto en 3 min. En cambio el 23
     * oct, después de dejar en Wyndham, fue al Riu Palace Kukulkan, esperó 7 min y salió con
     * pasajeros: eso sí es una salida.
     *
     * @param list<array> $before
     */
    protected function isReturn(array $leg, array $before): bool
    {
        $arrival = null;
        for ($k = count($before) - 1; $k >= 0; $k--) {
            $kind = $before[$k]['kind'];
            if ($kind === self::ARRIVAL) {
                $arrival = $before[$k];
                break;
            }
            if ($kind !== self::MANEUVER && $kind !== self::POSITIONING) {
                return false;
            }
        }

        if (!$arrival || $leg['start'] - $arrival['end'] > self::RETURN_WINDOW_MINUTES * 60) {
            return false;
        }

        // ¿Esperó en el punto de donde sale? Una recogida tiene espera; un regreso no.
        $prev = end($before);
        $waited = $prev && $this->meters($prev['destination'], $leg['origin']) <= self::SAME_SPOT_METERS
            ? $leg['start'] - $prev['end']
            : 0;

        return $waited < self::MIN_PICKUP_WAIT_SECONDS || $prev === $arrival;
    }

    /**
     * Motivo de duda que no sale de un solo viaje sino de la secuencia, o null.
     *
     * @param list<array> $before
     */
    protected function doubt(array $leg, array $before): ?string
    {
        $last = null;
        for ($k = count($before) - 1; $k >= 0; $k--) {
            if ($before[$k]['kind'] !== self::MANEUVER) {
                $last = $before[$k];
                break;
            }
        }

        // Llegada que arranca justo después de dejar una salida, en el mismo punto
        $previous = end($before);
        if ($leg['kind'] === self::ARRIVAL && $previous && $previous['kind'] === self::DEPARTURE
            && $leg['start'] - $previous['end'] < self::QUICK_TURN_SECONDS
            && $this->meters($previous['destination'], $leg['origin']) <= self::SAME_SPOT_METERS) {
            $last = $previous;
            return 'Salió del aeropuerto ' . max(1, intdiv($leg['start'] - $last['end'], 60))
                . ' min después de dejar pasajeros: puede ser una llegada o que fuera por otra salida';
        }

        // Regreso de una llegada dudosa: si aquella fue a recoger, este viaje fue la salida
        if ($leg['kind'] === self::RETURN && $last && $last['kind'] === self::ARRIVAL && $last['doubt'] !== null) {
            return 'Si el viaje anterior fue a recoger pasajeros, este fue la salida con ellos';
        }

        return null;
    }

    /**
     * [estado, motivos, lleva pasajeros]. Lo que confirmó una persona manda; si no, lo
     * dudoso cuenta como vacío hasta que alguien lo revise.
     */
    protected function status(array $leg): array
    {
        if ($review = $leg['review']) {
            return [self::CONFIRMED, [], $review['status'] === self::REVIEW_SERVICE];
        }

        if (!isset($leg['service'])) {
            return $leg['doubt'] ? [self::PENDING, [$leg['doubt']], false] : [null, [], false];
        }

        $reasons = $leg['doubt'] ? [$leg['doubt']] : [];
        if ($leg['service']['hotel'] === null) {
            $reasons[] = 'Punto sin nombre: puede ser un hotel nuevo, una casa o una parada del chofer';
        }
        if ($leg['kind'] === self::DEPARTURE) {
            $wait = $leg['service']['wait_seconds'];
            if ($wait === null) {
                $reasons[] = 'No se vio llegar al punto de recogida';
            } elseif ($wait < self::MIN_PICKUP_WAIT_SECONDS) {
                $reasons[] = 'Esperó ' . max(1, intdiv($wait, 60)) . ' min: muy poco para una recogida';
            }
        }

        return $reasons ? [self::PENDING, $reasons, false] : [self::PROBABLE, [], true];
    }

    public function isAirport(array $point): bool
    {
        if (($point['type'] ?? null) === 'airport_terminal') {
            return true;
        }

        foreach ($this->terminals as $terminal) {
            if ($this->meters($point, $terminal) <= self::AIRPORT_METERS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un lugar conocido que no es hotel (la base, la gasolinera) no es donde se sube o se
     * baja gente. Un punto sin lugar sí puede serlo: un hotel nuevo o una casa.
     */
    protected function isNotPickupSpot(array $point): bool
    {
        return ($point['type'] ?? null) !== null && $point['type'] !== 'hotel';
    }

    protected function meters(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($x)));
    }
}