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
    // Entre dos puntos de pasajeros sin pasar por el aeropuerto (29 oct: de Chiquilá a
    // Villa del Palmar). Puede ser un traslado o la unidad yendo vacía de un lado a otro.
    public const TRANSFER = 'traslado';
    // Fue por pasajeros que no se presentaron (sospecha del GPS o marcado por una persona)
    public const NO_SHOW = 'no_show';

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
    // Si después de esa espera regresa al aeropuerto, basta con menos: fue vacío a recoger y
    // salió con pasajeros. Medido: las dejadas duran de 1 a 8 min (2 nov: Moon Palace 1.5,
    // Hilton 6; 31 oct: Almaraza 8) y las recogidas después de posicionarse 15 a 19 min
    // (2 nov: Kempinski, de 12:42 a 12:59; 28 oct: Sunscape, de 18:10 a 18:29).
    protected const PICKUP_STAY_MINUTES = 10;
    // Entre esto y PICKUP_STAY_MINUTES no se puede saber solo con el GPS: se deja como
    // llegada pero por confirmar. Medido: dejadas de 1 a 8 min, recogidas de 3 a 25 min;
    // 31 oct Almaraza (dejada) y 3 nov Temptation (recogida) duraron los dos 8 min.
    protected const AMBIGUOUS_STAY_MINUTES = 7;
    // Posible no-show: esperó al menos esto en un hotel y de ahí no fue al aeropuerto.
    // 6 nov: 29 min en el Fairfield y se fue al Grand Oasis (Kevin confirmó no-show).
    protected const NO_SHOW_WAIT_MINUTES = 15;
    // Para buscar el hotel de una recogida detrás de las maniobras en la entrada
    protected const PICKUP_AREA_METERS = 1000;
    // Llegada que se detuvo antes del hotel (caseta, tráfico) y siguió hasta él: se junta
    // si el tramo que falta es así de corto y arranca así de pronto
    protected const APPROACH_MAX_KM = 5;
    protected const APPROACH_MAX_GAP_SECONDS = 900;
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
    // Un traslado: entre dos puntos de pasajeros, de al menos esta distancia, y con espera
    // en el origen (se subió alguien). Más corto o sin espera, es reposicionarse vacío.
    protected const TRANSFER_MIN_KM = 20;
    protected const TRANSFER_MIN_WAIT_SECONDS = 600;

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
        $legs = $this->mergeApproaches($legs);
        $out = [];
        $n = count($legs);

        for ($i = 0; $i < $n; $i++) {
            $leg = $legs[$i];
            $leg['review'] ??= null;
            $prev = $out[$i - 1] ?? null;
            $next = $legs[$i + 1] ?? null;

            $leg['kind'] = $this->kind($leg, $out, $legs, $i);
            $leg['doubt'] = $this->doubt($leg, $out, $legs, $i);

            // Datos del servicio
            if ($leg['kind'] === self::ARRIVAL) {
                $leg['service'] = [
                    'type' => self::ARRIVAL,
                    'terminal' => $leg['origin']['name'],
                    'hotel' => $leg['destination']['name'],
                    'place_id' => $leg['destination']['place_id'] ?? null,
                    'hotel_point' => [$leg['destination']['lat'], $leg['destination']['lng']],
                    'left_at' => $leg['start'],      // salió del aeropuerto con los pasajeros
                    'arrived_at' => $leg['end'],     // llegó al hotel
                    'stay_seconds' => $next ? $next['start'] - $leg['end'] : null,
                ];
            } elseif ($leg['kind'] === self::DEPARTURE) {
                [$hotelPoint, $reachedAt, $otherPoint] = $this->pickupPoint($leg, $out) + [2 => null];
                $leg['service'] = [
                    'type' => self::DEPARTURE,
                    'hotel' => $hotelPoint['name'] ?? null,
                    'place_id' => $hotelPoint['place_id'] ?? null,
                    'hotel_point' => [$hotelPoint['lat'], $hotelPoint['lng']],
                    'terminal' => $leg['destination']['name'],
                    'reached_hotel_at' => $reachedAt, // llegó por los pasajeros
                    // Otro hotel donde se detuvo justo antes: la recogida pudo ser ahí
                    'other_hotel' => $otherPoint['name'] ?? null,
                    'left_at' => $leg['start'],
                    'arrived_at' => $leg['end'],     // llegó al aeropuerto
                    'wait_seconds' => $reachedAt !== null ? $leg['start'] - $reachedAt : null,
                ];
            }

            if ($leg['kind'] === self::TRANSFER) {
                $leg['service'] = [
                    'type' => self::TRANSFER,
                    'hotel' => $leg['destination']['name'],
                    'place_id' => $leg['destination']['place_id'] ?? null,
                    'hotel_point' => [$leg['destination']['lat'], $leg['destination']['lng']],
                    'terminal' => null,
                    'reached_hotel_at' => $prev ? $prev['end'] : null,
                    'left_at' => $leg['start'],
                    'arrived_at' => $leg['end'],
                    'wait_seconds' => $prev ? $leg['start'] - $prev['end'] : null,
                ];
            }

            [$leg['status'], $leg['reasons'], $leg['with_passengers']] = $this->status($leg);
            $out[] = $leg;
        }

        return $this->markNoShows($out);
    }

    /**
     * @param list<array> $before Viajes anteriores ya clasificados
     * @param list<array> $legs Todos los viajes del día; $i es el actual
     */
    protected function kind(array $leg, array $before, array $legs, int $i): string
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
            // Cuánto se quedó: hasta que se va de verdad, sin contar las maniobras en la
            // entrada del hotel (2 nov, Kempinski: se movió 400 m a la salida al bulevar)
            $j = $this->leavingIndex($legs, $i);
            $leaving = $legs[$j] ?? null;
            $stay = $leaving ? $leaving['start'] - $leg['end'] : 0;

            if ($stay >= self::LONG_WAIT_MINUTES * 60) {
                return self::POSITIONING;
            }
            if ($stay >= self::PICKUP_STAY_MINUTES * 60 && $this->isAirport($leaving['destination'])) {
                return self::POSITIONING;
            }

            return self::ARRIVAL;
        }

        if ($toAirport) {
            if ($this->isNotPickupSpot($leg['origin'])) {
                return self::POSITIONING;
            }
            return $this->isReturn($leg, $before) ? self::RETURN : self::DEPARTURE;
        }

        // Entre dos puntos de pasajeros, lejos y con espera en el origen: posible traslado
        $prev = end($before) ?: null;
        $waited = $prev && $this->meters($prev['destination'], $leg['origin']) <= self::SAME_SPOT_METERS
            ? $leg['start'] - $prev['end']
            : 0;
        if ($leg['km'] >= self::TRANSFER_MIN_KM
            && $this->isPickupSpot($leg['origin'])
            && $this->isPickupSpot($leg['destination'])
            && $waited >= self::TRANSFER_MIN_WAIT_SECONDS) {
            return self::TRANSFER;
        }

        return self::POSITIONING;
    }

    /**
     * Marca los posibles no-shows: la unidad llegó vacía a un hotel (posicionamiento),
     * esperó NO_SHOW_WAIT_MINUTES o más y de ahí no salió hacia el aeropuerto, sino a otro
     * lado. Las maniobras cortas en la zona no cuentan como irse (5 nov: esperó en Ocean
     * Dream, se movió 870 m al Riu y de ahí salió al aeropuerto: no es no-show).
     *
     * Solo en hoteles y puntos de pasajeros del catálogo: en un punto sin nombre la espera
     * suele ser el chofer haciendo tiempo (6 nov: 51 min en el centro antes de Ocean View).
     * No cambia el tipo del viaje: le agrega un servicio de tipo no_show (la constancia:
     * llegada, espera y salida) y lo deja por confirmar. Si alguien ya lo marcó como
     * no-show, la constancia se queda y sale confirmado.
     *
     * @param list<array> $legs Ya clasificados
     * @return list<array>
     */
    protected function markNoShows(array $legs): array
    {
        $n = count($legs);
        for ($i = 0; $i < $n; $i++) {
            $leg = $legs[$i];
            $legs[$i]['no_show'] = null;
            $review = $leg['review']['status'] ?? null;

            if ($leg['kind'] !== self::POSITIONING
                || !in_array($leg['destination']['type'] ?? null, ['hotel', 'stop'], true)
                || !in_array($review, [null, self::REVIEW_NO_SHOW], true)) {
                continue;
            }

            $next = $legs[$i + 1] ?? null;
            $leaving = $legs[$this->leavingIndex($legs, $i)] ?? null;
            if (!$next || !$leaving) {
                continue; // fin del día: no hay cómo saber
            }

            // La espera es hasta que se movió por primera vez (6 nov: 10:14 a 10:46)
            $wait = $next['start'] - $leg['end'];
            if ($review === null
                && ($wait < self::NO_SHOW_WAIT_MINUTES * 60 || $this->isAirport($leaving['destination']))) {
                continue;
            }

            $legs[$i]['no_show'] = true;
            $legs[$i]['service'] = [
                'type' => self::NO_SHOW,
                'hotel' => $leg['destination']['name'],
                'place_id' => $leg['destination']['place_id'] ?? null,
                'hotel_point' => [$leg['destination']['lat'], $leg['destination']['lng']],
                'terminal' => null,
                'reached_hotel_at' => $leg['end'],  // llegó al hotel
                'left_at' => $leg['end'],
                'arrived_at' => $next['start'],     // se fue sin pasajeros
                'wait_seconds' => $wait,
            ];
            $legs[$i]['with_passengers'] = false;
            if ($review === null) {
                $legs[$i]['status'] = self::PENDING;
                $legs[$i]['reasons'] = ['Posible no-show: esperó ' . intdiv($wait, 60) . ' min en '
                    . $leg['destination']['name'] . ' y de ahí no fue al aeropuerto'];
            }
        }

        return $legs;
    }

    /**
     * Junta una llegada que se detuvo en un punto sin nombre antes del hotel con el tramo
     * corto que la lleva hasta él. 27 oct: se paró en la caseta de Playacar y 2 min después
     * siguió 3.8 km hasta Viva Wyndham Maya. 31 oct: 6 min en tráfico y luego 2.6 km hasta
     * Hyatt Zilara. Sin juntarlas, la llegada quedaba "sin nombre" y lo que seguía se leía
     * como una salida desde el hotel.
     *
     * @param list<array> $legs
     * @return list<array>
     */
    protected function mergeApproaches(array $legs): array
    {
        $out = [];
        $n = count($legs);
        for ($i = 0; $i < $n; $i++) {
            $leg = $legs[$i];
            $next = $legs[$i + 1] ?? null;

            if ($next
                && ($leg['review'] ?? null) === null && ($next['review'] ?? null) === null
                && ($leg['destination']['name'] ?? null) === null
                && $this->isAirport($leg['origin'])
                && in_array($next['destination']['type'] ?? null, ['hotel', 'stop'], true)
                && $next['km'] <= self::APPROACH_MAX_KM
                && $next['start'] - $leg['end'] <= self::APPROACH_MAX_GAP_SECONDS
                && $this->meters($leg['destination'], $next['origin']) <= self::SAME_SPOT_METERS) {
                $leg['end'] = $next['end'];
                $leg['km'] += $next['km'];
                $leg['destination'] = $next['destination'];
                $i++;
            }

            $out[] = $leg;
        }

        return $out;
    }

    /**
     * Índice del viaje con el que la unidad se va del destino del viaje $i: el primero
     * después de $i que no sea una maniobra corta en la zona (las que no llegan al
     * aeropuerto). Puede ser count($legs) si el día termina ahí.
     *
     * @param list<array> $legs
     */
    protected function leavingIndex(array $legs, int $i): int
    {
        $j = $i + 1;
        while (isset($legs[$j])
            && $legs[$j]['km'] < self::MIN_SERVICE_KM
            && !$this->isAirport($legs[$j]['destination'])) {
            $j++;
        }

        return $j;
    }

    /**
     * Dónde se subieron los pasajeros de una salida y a qué hora llegó la unidad ahí:
     * [punto, timestamp o null]. Si antes hubo maniobras cortas (acomodarse en la entrada,
     * salir al bulevar), el punto es donde se detuvo antes de ellas. 2 nov: esperó en el
     * lobby del Kempinski de 12:42 a 12:57 y la salida arrancó a 400 m, en el bulevar.
     *
     * @param list<array> $before
     */
    protected function pickupPoint(array $leg, array $before): array
    {
        $k = count($before) - 1;
        while ($k >= 0 && $before[$k]['kind'] === self::MANEUVER && $before[$k]['km'] < self::MIN_SERVICE_KM) {
            $k--;
        }

        $skipped = $k < count($before) - 1;
        if ($skipped && $k >= 0
            && !$this->isAirport($before[$k]['destination'])
            && $this->meters($before[$k]['destination'], $leg['origin']) <= self::PICKUP_AREA_METERS) {
            $point = $before[$k]['destination'];
            $originName = $leg['origin']['name'] ?? null;
            // Si la salida arranca en otro lugar con nombre, ése es el hotel: la parada de
            // antes fue en otro sitio (5 nov: llegó vacía a Ocean Dream, se movió 870 m y
            // recogió en Riu Palace Las Américas)
            // Con el puro GPS no se sabe en cuál de los dos subieron (28 oct: esperó en
            // Sunscape y luego junto al NYX, y fue Sunscape): se queda la salida desde donde
            // arrancó y el otro hotel va como alternativa, por confirmar
            if ($originName !== null && ($point['name'] ?? null) !== null && $point['name'] !== $originName) {
                $prev = end($before);
                return [$leg['origin'], $this->meters($prev['destination'], $leg['origin']) <= self::SAME_SPOT_METERS ? $prev['end'] : null, $point];
            }
            // El nombre del hotel manda sobre el punto sin nombre de la salida al bulevar
            if (($point['name'] ?? null) === null && $originName !== null) {
                $point = $leg['origin'];
            }
            return [$point, $before[$k]['end']];
        }

        $prev = end($before) ?: null;
        if ($prev && $this->meters($prev['destination'], $leg['origin']) <= self::SAME_SPOT_METERS) {
            // Esperó en un hotel y el viaje arrancó ya en el bulevar, frente a otro: el hotel
            // es donde esperó (27 oct: esperó en Coral Beach y arrancó frente al Aloft, 160 m)
            if (in_array($prev['destination']['type'] ?? null, ['hotel', 'stop'], true)
                && ($prev['destination']['name'] ?? null) !== ($leg['origin']['name'] ?? null)) {
                return [$prev['destination'], $prev['end']];
            }
            return [$leg['origin'], $prev['end']];
        }

        return [$leg['origin'], null];
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
    protected function doubt(array $leg, array $before, array $legs, int $i): ?string
    {
        // Llegada que se quedó en el hotel lo que tarda una dejada larga o una recogida
        // corta, y luego volvió al aeropuerto
        if ($leg['kind'] === self::ARRIVAL) {
            $leaving = $legs[$this->leavingIndex($legs, $i)] ?? null;
            $stay = $leaving ? $leaving['start'] - $leg['end'] : 0;
            if ($leaving && $this->isAirport($leaving['destination'])
                && $stay >= self::AMBIGUOUS_STAY_MINUTES * 60) {
                return 'Esperó ' . intdiv($stay, 60) . ' min en el hotel y volvió al aeropuerto: '
                    . 'si dejó pasajeros fue una llegada; si fue vacío a recoger, el viaje siguiente fue la salida';
            }
        }

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
        if ($leg['kind'] === self::TRANSFER) {
            $reasons[] = 'Viaje entre dos puntos sin pasar por el aeropuerto: puede ser un traslado o un movimiento vacío';
        }
        if ($leg['service']['hotel'] === null) {
            $reasons[] = 'Punto sin nombre: puede ser un hotel nuevo, una casa o una parada del chofer';
        }
        if (($leg['service']['other_hotel'] ?? null) !== null) {
            $reasons[] = 'Antes se detuvo en ' . $leg['service']['other_hotel'] . ': los pasajeros pudieron subir ahí';
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

        // Un punto de pasajeros junto al aeropuerto no es el aeropuerto (28 oct: la estación
        // del Tren Maya está a 1.1 km de T3)
        if (in_array($point['type'] ?? null, ['hotel', 'stop'], true)) {
            return false;
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
        return ($point['type'] ?? null) !== null && !in_array($point['type'], ['hotel', 'stop'], true);
    }

    /**
     * Donde se sube o baja gente: un hotel, un punto de pasajeros (estación, muelle, casa
     * o residencial) o un punto sin nombre, que puede ser cualquiera de esos.
     */
    protected function isPickupSpot(array $point): bool
    {
        return !$this->isNotPickupSpot($point) && !$this->isAirport($point);
    }

    protected function meters(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $x = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($x)));
    }
}