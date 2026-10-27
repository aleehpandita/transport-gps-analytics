<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Position;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

class TripBuilderService
{
    /**
     * Velocidad (km/h) por debajo de la cual se considera que la unidad está detenida.
     * No se usa speed == 0 porque el GPS reporta 1 o 2 km/h de ruido estando parada.
     */
    protected const IDLE_SPEED_KMH = 3;

    /**
     * Radio (km) de la regla de permanencia: la unidad tiene que estar quieta de verdad.
     * Antes eran 200 m (19 oct), para atrapar bajadas con maniobras en la entrada del hotel;
     * desde el 22 oct esas las atrapa la regla de lugar, y con 200 m una fila de semáforo
     * (avanza, se para, avanza) cortaba el viaje: pasó dos días seguidos en el mismo cruce.
     */
    protected const DWELL_RADIUS_KM = 0.06;

    /**
     * Radio (km) que hay que dejar atrás, después de cerrar un viaje, para que empiece el
     * siguiente. Así las maniobras en la entrada del hotel o en el estacionamiento no cuentan
     * como un viaje nuevo.
     */
    protected const LEAVE_RADIUS_KM = 0.2;

    /**
     * Semáforos: un alto a menos de SIGNAL_METERS de un semáforo de OpenStreetMap solo cierra
     * el viaje si dura SIGNAL_DWELL_MINUTES o más. Un rojo largo en Cancún puede pasar de 3 min.
     */
    protected const SIGNAL_METERS = 40;
    protected const SIGNAL_DWELL_MINUTES = 10;

    /**
     * Segundos que la ignición debe seguir en false para considerar el motor apagado.
     * Filtra señales que parpadean: Kevs1 tenía la ignición por voltaje con umbral de 13.2 V,
     * y su alternador carga justo alrededor de ese valor, así que ignition cambiaba de
     * true a false cada 1 a 5 segundos, incluso a 56 km/h (ver docs/piloto-fmc920.md).
     */
    protected const IGNITION_DEBOUNCE_SECONDS = 60;

    /**
     * Silencios: en movimiento el FMC920 guarda una posición por distancia (100 m) o por giro
     * (10°). Detenida no avanza ni gira, así que no guarda nada: una parada corta aparece como
     * un hueco entre dos posiciones casi en el mismo lugar, sin ninguna lectura en cero.
     * Con datos reales de Kevs1 (20 oct 2026): las 4 bajadas en la T3 fueron silencios de
     * 49 a 100 s con la unidad a 5-7 km/h antes y después.
     */
    protected const SILENCE_MIN_SECONDS = 30;
    protected const SILENCE_MAX_METERS = 30;

    /**
     * Regla de lugar de servicio: dentro del radio de un lugar (banqueta del aeropuerto, lobby
     * de hotel) basta con ir a menos de PLACE_SLOW_KMH por PLACE_STOP_SECONDS para cerrar el
     * viaje. Las bajadas y recogidas duran de 1 a 3 minutos, lo mismo que un semáforo largo,
     * así que solo el lugar las distingue.
     */
    protected const PLACE_SLOW_KMH = 10;
    protected const PLACE_STOP_SECONDS = 15;

    /**
     * Distancia mínima (km) desde el inicio del viaje para aplicar la regla de lugar. Evita
     * cerrar un viaje que acaba de arrancar en el mismo lobby donde recogió a los pasajeros.
     */
    protected const PLACE_MIN_TRIP_KM = 0.3;

    /**
     * Regla 4 (vuelta): bajadas rápidas en hoteles que no están en el catálogo.
     *
     * Medido el 25 oct: Grand Oasis (2 min) y Dreams Sands (3.5 min) no cerraron el viaje
     * porque duraron menos que la regla de permanencia y el hotel no estaba dado de alta.
     * Lo que las distingue de un semáforo es lo que pasa después: en un semáforo la unidad
     * sigue derecho; después de una bajada da la vuelta y regresa por donde vino (llegó
     * por el bulevar hacia el norte y se fue hacia el sur).
     *
     * Se aplica al cerrar cada viaje: si adentro hay una parada de TURN_STOP_SECONDS o más,
     * en menos de TURN_RADIUS_M, y el rumbo de salida gira TURN_MIN_DEGREES o más contra el
     * de llegada, el viaje se parte ahí. Probado con los viajes reales del 19 al 25 oct: de
     * 92 paradas de 30 s o más dentro de viajes, solo 3 cumplen las condiciones (las 2 bajadas
     * del 25 y una del 19 junto al Iberostar); ninguna en semáforos ni en filas de tráfico.
     */
    protected const TURN_STOP_SECONDS = 60;
    protected const TURN_RADIUS_M = 40;
    protected const TURN_SLOW_KMH = 12;
    protected const TURN_MIN_DEGREES = 120;
    // Distancia a la que se mide el rumbo de llegada y de salida
    protected const TURN_BEARING_METERS = 200;
    // Lejos del inicio y del fin del viaje, que ya resuelven las otras reglas
    protected const TURN_EDGE_KM = 0.3;
    // Cerca del aeropuerto las vueltas son maniobras entre terminales, no bajadas
    protected const TURN_AIRPORT_METERS = 1500;

    /**
     * Regla 5 (junto a un hotel): bajadas y recogidas en las que la unidad sigue de largo,
     * sin dar la vuelta. Medido el 25 y 26 oct: Cancun Bay (73 s) y Fiesta Americana Villas
     * (67 s), las dos a menos de 120 m de un hotel. Basta una parada más corta, pero solo
     * junto a un hotel (del catálogo o candidato de OpenStreetMap) y lejos de semáforos con
     * un margen más amplio: en el centro hay hoteles junto a cruces con fila (Soberanis,
     * 25 oct, a 70 m de un semáforo).
     */
    protected const HOTEL_STOP_SECONDS = 45;
    protected const HOTEL_STOP_METERS = 120;
    protected const HOTEL_STOP_SIGNAL_METERS = 80;

    // Dos paradas así de cerca son la misma (maniobras en la entrada del hotel)
    protected const INNER_STOP_MERGE_METERS = 150;

    /**
     * Segundos de movimiento sostenido requeridos para confirmar inicio de viaje.
     */
    protected int $startConfirmationSeconds;

    /**
     * Minutos de motor apagado sostenido requeridos para confirmar fin de viaje.
     * Calibrado con datos reales: una parada de ~8 min con motor apagado NO es fin de viaje,
     * una parada de >1.5h sí lo es. Punto de partida conservador: 15 minutos.
     * Con la regla de permanencia casi siempre se cierra antes por esa regla; esta queda
     * como respaldo.
     */
    protected int $endConfirmationMinutes;

    /**
     * Minutos dentro del radio DWELL_RADIUS_KM requeridos para cerrar el viaje
     * (regla de permanencia) cuando NO es un lugar conocido. No depende de la ignición.
     * 3 min el 19 oct; 5 min desde el 22 oct: un rojo de 3 min 10 s cortaba viajes, y las
     * bajadas rápidas en hoteles y terminales ya las atrapa la regla de lugar.
     */
    protected int $dwellEndMinutes;

    protected ZoneMatchingService $zoneMatcher;

    protected ?SignalIndex $signalIndex = null;

    /** @var Collection<int, Place>|null Lugares activos, se cargan una vez por instancia */
    protected ?Collection $places = null;

    /** @var Collection<int, Place>|null Hoteles del catálogo y candidatos de OpenStreetMap */
    protected ?Collection $hotels = null;

    public function __construct(
        int $startConfirmationSeconds = 30,
        int $endConfirmationMinutes = 15,
        ?ZoneMatchingService $zoneMatcher = null,
        int $dwellEndMinutes = 5
    ) {
        $this->startConfirmationSeconds = $startConfirmationSeconds;
        $this->endConfirmationMinutes = $endConfirmationMinutes;
        $this->zoneMatcher = $zoneMatcher ?? new ZoneMatchingService();
        $this->dwellEndMinutes = $dwellEndMinutes;
    }

    /**
     * Procesa las posiciones de un vehículo en un rango de tiempo y crea los trips detectados.
     * Solo usa posiciones con valid = true.
     *
     * Si un viaje ya guardado empezó antes de $from y sigue abierto en $from, el rango se
     * extiende hasta su inicio. Así el viaje se recalcula completo y no aparece una versión
     * cortada que empiece justo en $from.
     *
     * @return Collection<Trip>
     */
    public function buildForVehicle(Vehicle $vehicle, string $from, string $to): Collection
    {
        $spanning = Trip::where('vehicle_id', $vehicle->id)
            ->where('data_source', 'real')
            ->where('started_at', '<', $from)
            ->where('ended_at', '>=', $from)
            ->orderBy('started_at')
            ->first();

        if ($spanning) {
            $from = $spanning->started_at->toDateTimeString();
        }

        $positions = Position::where('vehicle_id', $vehicle->id)
            ->where('valid', true)
            ->whereBetween('device_time', [$from, $to])
            ->orderBy('device_time')
            ->get();

        return $this->build($vehicle, $positions);
    }

    /**
     * Recorre las posiciones en orden y detecta los viajes.
     *
     * Inicio: motor encendido y movimiento sostenido por startConfirmationSeconds.
     * Fin, lo que pase primero:
     *   - Regla 1: motor apagado por endConfirmationMinutes.
     *   - Regla 2 (permanencia): la unidad se queda quieta, dentro de DWELL_RADIUS_KM, por
     *     dwellEndMinutes (SIGNAL_DWELL_MINUTES junto a un semáforo). Para paradas en
     *     lugares que todavía no están en el catálogo, como una casa.
     *   - Regla 3 (lugar de servicio): dentro del radio de un lugar del catálogo (places),
     *     a menos de PLACE_SLOW_KMH por PLACE_STOP_SECONDS. Atrapa las bajadas y recogidas
     *     rápidas, que duran lo mismo que un semáforo.
     *
     *   - Regla 4 (vuelta): al cerrar, el viaje se parte donde la unidad se detuvo y dio la
     *     vuelta. Atrapa bajadas rápidas en hoteles que todavía no están en el catálogo.
     *   - Regla 5 (junto a un hotel): también se parte en una parada corta junto a un hotel,
     *     del catálogo o de OpenStreetMap, aunque siga de largo.
     *
     * Las reglas 2 y 3 cuentan los silencios (huecos sin posiciones con la unidad en el
     * mismo lugar) como tiempo detenida.
     *
     * @param Collection<Position> $positions Ya ordenadas por device_time, del mismo vehículo.
     * @return Collection<Trip>
     */
    public function build(Vehicle $vehicle, Collection $positions): Collection
    {
        $trips = collect();

        $tripStart = null;         // Position donde arrancó el viaje actual
        $tripPositions = [];       // Posiciones acumuladas del viaje en curso
        $endCandidate = null;      // Última lectura con motor encendido antes de apagarse (candidato a fin, regla 1)
        $dwellAnchor = null;       // Position donde se detuvo (candidato a fin, regla 2)
        $movingSince = null;       // Position donde empezó a evaluarse el posible inicio
        $hasMovedInWindow = false; // true si dentro de la ventana actual hubo movimiento real
        $lastIgnitionOn = null;    // Última lectura con ignition = true, para el filtro de ignición
        $lastClosePoint = null;    // Donde se cerró el viaje anterior
        $placeStop = null;         // Position donde empezó a detenerse dentro de un lugar (candidato a fin, regla 3)
        $placeStopPlaceId = null;  // Lugar de ese candidato
        $traveledFar = false;      // true cuando el viaje ya se alejó PLACE_MIN_TRIP_KM de su inicio

        foreach ($positions as $position) {
            $ignitionOn = $this->isIgnitionOn($position, $lastIgnitionOn);

            if ($position->ignition) {
                $lastIgnitionOn = $position;
            }

            if ($tripStart === null) {
                // Buscando inicio de viaje.
                // No exigimos speed > 0 en CADA lectura (un alto o semáforo con el motor
                // encendido no debe reiniciar el conteo). Solo exigimos que el motor
                // esté encendido de forma sostenida y que haya habido movimiento real
                // en algún punto de esa ventana de tiempo.
                if (!$ignitionOn) {
                    $movingSince = null;
                    $hasMovedInWindow = false;
                    continue;
                }

                // Mientras siga en el radio donde terminó el viaje anterior (maniobras en la
                // entrada del hotel, en el estacionamiento) no cuenta como inicio.
                $insideLastStop = $lastClosePoint !== null
                    && $this->distanceKm($lastClosePoint, $position) <= self::LEAVE_RADIUS_KM;

                $stillWaiting = !$hasMovedInWindow && $position->speed < self::IDLE_SPEED_KMH;

                if ($movingSince === null || $insideLastStop || $stillWaiting) {
                    // La ventana se recorre a la lectura más reciente mientras siga detenida
                    // o dentro del radio. Así el viaje empieza cuando de verdad sale y no
                    // carga la espera.
                    $movingSince = $position;
                    $hasMovedInWindow = !$insideLastStop && $position->speed >= self::IDLE_SPEED_KMH;
                    continue;
                }

                if ($position->speed > 0) {
                    $hasMovedInWindow = true;
                }

                // abs(): Carbon 3 regresa diffInSeconds con signo (puede ser negativo
                // según el orden de los objetos comparados). Siempre queremos la
                // magnitud del tiempo transcurrido, sin importar el orden.
                $elapsed = abs($position->device_time->diffInSeconds($movingSince->device_time));

                if ($hasMovedInWindow && $elapsed >= $this->startConfirmationSeconds) {
                    $tripStart = $movingSince;
                    $tripPositions = [$movingSince, $position];
                    $movingSince = null;
                    $hasMovedInWindow = false;
                    $lastClosePoint = null;
                }

                continue;
            }

            // Viaje en curso
            $previous = end($tripPositions);
            $tripPositions[] = $position;
            $closingPoint = null;

            // Silencio: hueco largo entre dos posiciones casi en el mismo lugar = estuvo detenida
            $silence = $previous instanceof Position && $this->isSilence($previous, $position);

            if (!$traveledFar && $this->distanceKm($tripStart, $position) >= self::PLACE_MIN_TRIP_KM) {
                $traveledFar = true;
            }

            // Regla 1: motor apagado sostenido.
            if (!$ignitionOn) {
                if ($endCandidate === null) {
                    // El fin es la última lectura con el motor encendido, no la lectura en la
                    // que el filtro confirmó el apagado (que llega IGNITION_DEBOUNCE_SECONDS después).
                    $endCandidate = $lastIgnitionOn ?? $position;
                }

                $minutesSinceCandidate = abs($position->device_time->diffInMinutes($endCandidate->device_time));

                if ($minutesSinceCandidate >= $this->endConfirmationMinutes) {
                    $closingPoint = $endCandidate;
                }
            } else {
                // El motor volvió a encenderse antes de confirmarse el fin: se cancela el candidato.
                $endCandidate = null;
            }

            // Regla 3: detenida en un lugar de servicio (banqueta del aeropuerto, lobby).
            // Va antes que la de permanencia porque cierra mucho más rápido.
            if ($closingPoint === null) {
                $place = $traveledFar ? $this->placeAt($position) : null;
                $slowHere = $position->speed < self::PLACE_SLOW_KMH || $silence;

                if ($place === null || !$slowHere || $place->id !== $placeStopPlaceId) {
                    // Fuera del lugar, o pasando sin detenerse: no hay candidato
                    $placeStop = null;
                    $placeStopPlaceId = null;
                }

                if ($place !== null && $slowHere) {
                    if ($placeStop === null) {
                        // Si llegó en silencio, se detuvo desde la posición anterior
                        $startedAtPrevious = $silence && $this->placeAt($previous)?->id === $place->id;
                        $placeStop = $startedAtPrevious ? $previous : $position;
                        $placeStopPlaceId = $place->id;
                    }

                    $stopSeconds = abs($position->device_time->diffInSeconds($placeStop->device_time));

                    if ($stopSeconds >= self::PLACE_STOP_SECONDS) {
                        $closingPoint = $placeStop;
                    }
                }
            }

            // Regla 2: permanencia dentro del radio.
            if ($closingPoint === null) {
                if ($dwellAnchor !== null && $this->distanceKm($dwellAnchor, $position) > self::DWELL_RADIUS_KM) {
                    // Salió del radio antes de cumplir el tiempo: era tráfico o un semáforo.
                    $dwellAnchor = null;
                }

                if ($dwellAnchor === null) {
                    if ($silence) {
                        // Estuvo detenida desde la posición anterior, aunque ninguna lectura diga 0 km/h
                        $dwellAnchor = $previous;
                    } elseif ($position->speed < self::IDLE_SPEED_KMH) {
                        $dwellAnchor = $position;
                    }
                }

                if ($dwellAnchor !== null) {
                    $dwellSeconds = abs($position->device_time->diffInSeconds($dwellAnchor->device_time));

                    if ($dwellSeconds >= $this->dwellMinutesAt($dwellAnchor) * 60) {
                        $closingPoint = $dwellAnchor;
                    }
                }
            }

            if ($closingPoint !== null) {
                $this->closeWithTurnarounds($vehicle, $tripPositions, $closingPoint, $trips);
                $lastClosePoint = $closingPoint;

                // Reiniciar estado para buscar el siguiente viaje
                $tripStart = null;
                $tripPositions = [];
                $endCandidate = null;
                $dwellAnchor = null;
                $placeStop = null;
                $placeStopPlaceId = null;
                $traveledFar = false;
                $movingSince = null;
                $hasMovedInWindow = false;
            }
        }

        // Si se acabaron las posiciones y todavía había un viaje abierto, se guarda de forma
        // provisional con la mejor información disponible: el candidato a fin si ya había uno,
        // o si no, la última posición conocida. Puede ser un viaje en curso, o uno cuyas
        // posiciones todavía no terminan de llegar (el FMC920 puede entregarlas con horas de
        // retraso, ver docs/piloto-fmc920.md). No queda cortado: closeTrip() lo recalcula y
        // lo actualiza en las siguientes corridas, cuando ya haya más posiciones.
        if ($tripStart !== null && !empty($tripPositions)) {
            $lastPosition = end($tripPositions);
            $closingPoint = $endCandidate ?? $lastPosition;
            $this->closeWithTurnarounds($vehicle, $tripPositions, $closingPoint, $trips);
        }

        return $trips;
    }

    /**
     * Cierra el viaje partiéndolo en cada bajada o recogida que encuentren las reglas 4 y 5.
     * Si no hay ninguna, es un solo viaje.
     *
     * @param Position[] $positions
     */
    protected function closeWithTurnarounds(Vehicle $vehicle, array $positions, Position $closingPoint, Collection $trips): void
    {
        $positions = array_values(array_filter(
            $positions,
            fn (Position $p) => $p->device_time->lte($closingPoint->device_time)
        ));

        $from = 0;
        foreach ($this->findInnerStops($positions) as [$stopStart, $stopEnd]) {
            $segment = array_slice($positions, $from, $stopStart - $from + 1);
            if (count($segment) >= 2) {
                $trips->push($this->closeTrip($vehicle, $segment, end($segment)));
            }
            // El siguiente tramo arranca cuando se va de la parada
            $from = $stopEnd;
        }

        $segment = array_slice($positions, $from);
        if (count($segment) >= 2) {
            $trips->push($this->closeTrip($vehicle, $segment, end($segment)));
        }
    }

    /**
     * Paradas dentro del viaje que fueron bajadas o recogidas: [índice donde se detuvo,
     * índice donde se fue], en orden. Regla 4: dio la vuelta. Regla 5: junto a un hotel.
     *
     * @param Position[] $positions
     * @return list<array{0: int, 1: int}>
     */
    protected function findInnerStops(array $positions): array
    {
        $n = count($positions);
        if ($n < 3) {
            return [];
        }

        $first = $positions[0];
        $last = $positions[$n - 1];
        $found = [];

        for ($i = 0; $i < $n; $i++) {
            $anchor = $positions[$i];
            if ($anchor->speed >= self::TURN_SLOW_KMH) {
                continue;
            }

            // Hasta dónde sigue detenida (o casi) en el mismo punto
            $j = $i;
            while ($j + 1 < $n
                && $positions[$j + 1]->speed < self::TURN_SLOW_KMH
                && $this->distanceKm($anchor, $positions[$j + 1]) * 1000 <= self::TURN_RADIUS_M) {
                $j++;
            }

            $seconds = abs($positions[$j]->device_time->diffInSeconds($anchor->device_time));
            $start = $i;
            $i = $j;

            if ($seconds < self::HOTEL_STOP_SECONDS
                || $this->distanceKm($anchor, $first) < self::TURN_EDGE_KM
                || $this->distanceKm($anchor, $last) < self::TURN_EDGE_KM
                || $this->nearAirport($anchor)) {
                continue;
            }

            $isStop = ($seconds >= self::TURN_STOP_SECONDS && $this->turnedAround($positions, $start, $j))
                || ($this->nearHotel($anchor) && !$this->signals()->isNear($anchor->latitude, $anchor->longitude, self::HOTEL_STOP_SIGNAL_METERS));

            if (!$isStop || $this->signals()->isNear($anchor->latitude, $anchor->longitude, self::SIGNAL_METERS)) {
                continue;
            }

            // Pegada a la anterior: es la misma parada (entró, se acomodó, bajó gente)
            $previous = end($found);
            if ($previous && $this->distanceKm($positions[$previous[0]], $anchor) * 1000 <= self::INNER_STOP_MERGE_METERS) {
                $found[count($found) - 1][1] = $j;
                continue;
            }

            $found[] = [$start, $j];
        }

        return $found;
    }

    /**
     * true si el rumbo de salida (parada → 200 m después) gira TURN_MIN_DEGREES o más contra
     * el de llegada (200 m antes → parada).
     *
     * @param Position[] $positions
     */
    protected function turnedAround(array $positions, int $start, int $end): bool
    {
        $n = count($positions);
        $anchor = $positions[$start];

        $before = $start;
        while ($before > 0 && $this->distanceKm($positions[$before], $anchor) * 1000 < self::TURN_BEARING_METERS) {
            $before--;
        }
        $after = $end;
        while ($after < $n - 1 && $this->distanceKm($positions[$after], $positions[$end]) * 1000 < self::TURN_BEARING_METERS) {
            $after++;
        }

        if ($before === $start || $after === $end) {
            return false;
        }

        $turn = $this->angleBetween(
            $this->bearing($positions[$before], $anchor),
            $this->bearing($positions[$end], $positions[$after])
        );

        return $turn >= self::TURN_MIN_DEGREES;
    }

    protected function nearHotel(Position $position): bool
    {
        foreach ($this->hotels() as $hotel) {
            if ($this->haversineKm($position->latitude, $position->longitude, $hotel->latitude, $hotel->longitude) * 1000 <= self::HOTEL_STOP_METERS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hoteles del catálogo y los candidatos de OpenStreetMap (inactivos): un hotel nuevo
     * casi siempre ya está en el mapa aunque no lo hayamos dado de alta.
     *
     * @return Collection<int, Place>
     */
    protected function hotels(): Collection
    {
        return $this->hotels ??= Place::where('type', 'hotel')->get(['id', 'latitude', 'longitude']);
    }

    protected function nearAirport(Position $position): bool
    {
        foreach ($this->activePlaces() as $place) {
            if ($place->type === 'airport_terminal'
                && $this->haversineKm($position->latitude, $position->longitude, $place->latitude, $place->longitude) * 1000 <= self::TURN_AIRPORT_METERS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rumbo de $a hacia $b en grados (0 = norte, 90 = este).
     */
    protected function bearing(Position $a, Position $b): float
    {
        $lat1 = deg2rad($a->latitude);
        $lat2 = deg2rad($b->latitude);
        $dLng = deg2rad($b->longitude - $a->longitude);

        $y = sin($dLng) * cos($lat2);
        $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLng);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /**
     * Cuánto giró entre dos rumbos, de 0 (sigue derecho) a 180 (da la vuelta).
     */
    protected function angleBetween(float $a, float $b): float
    {
        $diff = abs($a - $b);

        return $diff > 180 ? 360 - $diff : $diff;
    }

    /**
     * true si entre $previous y $position la unidad estuvo detenida aunque no haya lecturas
     * en cero: pasó SILENCE_MIN_SECONDS o más y se movió SILENCE_MAX_METERS o menos.
     */
    protected function isSilence(Position $previous, Position $position): bool
    {
        $seconds = abs($position->device_time->diffInSeconds($previous->device_time));

        return $seconds >= self::SILENCE_MIN_SECONDS
            && $this->distanceKm($previous, $position) * 1000 <= self::SILENCE_MAX_METERS;
    }

    /**
     * Minutos de permanencia para cerrar el viaje en este punto: más junto a un semáforo.
     */
    protected function dwellMinutesAt(Position $anchor): int
    {
        $nearSignal = $this->signals()->isNear($anchor->latitude, $anchor->longitude, self::SIGNAL_METERS);

        return $nearSignal ? max(self::SIGNAL_DWELL_MINUTES, $this->dwellEndMinutes) : $this->dwellEndMinutes;
    }

    protected function signals(): SignalIndex
    {
        return $this->signalIndex ??= new SignalIndex();
    }

    /**
     * Lugar de servicio donde está la posición, o null. Si dos se enciman, gana el más cercano.
     */
    protected function placeAt(Position $position): ?Place
    {
        $best = null;
        $bestKm = INF;

        foreach ($this->activePlaces() as $place) {
            $km = $this->haversineKm($position->latitude, $position->longitude, $place->latitude, $place->longitude);

            if ($km * 1000 <= $place->radius_m && $km < $bestKm) {
                $best = $place;
                $bestKm = $km;
            }
        }

        return $best;
    }

    /**
     * @return Collection<int, Place>
     */
    protected function activePlaces(): Collection
    {
        return $this->places ??= Place::where('is_active', true)->get();
    }

    /**
     * Ignición con filtro: el motor cuenta como encendido si esta lectura dice true, o si
     * hubo una lectura con true hace menos de IGNITION_DEBOUNCE_SECONDS. Así un parpadeo
     * de la señal no se toma como motor apagado.
     */
    protected function isIgnitionOn(Position $position, ?Position $lastIgnitionOn): bool
    {
        if ($position->ignition) {
            return true;
        }

        if ($lastIgnitionOn === null) {
            return false;
        }

        $secondsSinceOn = abs($position->device_time->diffInSeconds($lastIgnitionOn->device_time));

        return $secondsSinceOn < self::IGNITION_DEBOUNCE_SECONDS;
    }

    /**
     * Calcula las métricas del viaje y lo guarda en trips.
     * ended_at usa el momento del candidato a fin, no el momento de confirmación,
     * para no inflar la duración con el tiempo de espera de confirmación.
     *
     * Idempotente y autocorrectivo: la llave es vehículo + started_at. Si el viaje ya existe,
     * se recalcula y se actualiza con las posiciones actuales, en lugar de dejarlo como estaba.
     * Así, un viaje guardado de forma provisional (cortado porque faltaban posiciones) se
     * completa cuando trips:build vuelve a correr sobre un rango que lo incluye.
     *
     * Si el inicio cambió entre corridas (las posiciones llegan tarde y por partes, ver
     * docs/piloto-fmc920.md), la versión anterior queda con otro started_at. Por eso, al
     * guardar, se borran los otros viajes del mismo vehículo que se enciman en horario:
     * una unidad no puede estar en dos viajes a la vez, así que son versiones viejas
     * del mismo viaje.
     *
     * @param Position[] $positions
     */
    protected function closeTrip(Vehicle $vehicle, array $positions, Position $endCandidate): Trip
    {
        $tripPositions = collect($positions)->filter(
            fn (Position $p) => $p->device_time->lte($endCandidate->device_time)
        )->values();

        $first = $tripPositions->first();
        $last = $tripPositions->last();

        $distanceKm = $this->calculateTripDistanceKm($tripPositions);
        [$stopsCount, $stoppedSeconds] = $this->calculateStops($tripPositions);

        $originZone = $this->zoneMatcher->resolve($first->latitude, $first->longitude);
        $destinationZone = $this->zoneMatcher->resolve($last->latitude, $last->longitude);

        $trip = Trip::updateOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'started_at' => $first->device_time,
            ],
            [
                'origin_zone_id' => $originZone?->id,
                'destination_zone_id' => $destinationZone?->id,
                'ended_at' => $last->device_time,
                'duration_seconds' => abs($last->device_time->diffInSeconds($first->device_time)),
                'distance_km' => $distanceKm,
                'average_speed' => $tripPositions->avg('speed'),
                'max_speed' => $tripPositions->max('speed'),
                'stops_count' => $stopsCount,
                'stopped_seconds' => $stoppedSeconds,
                'data_source' => 'real',
            ]
        );

        Trip::where('vehicle_id', $vehicle->id)
            ->where('data_source', 'real')
            ->where('id', '!=', $trip->id)
            ->where('started_at', '<', $trip->ended_at)
            ->where('ended_at', '>', $trip->started_at)
            ->delete();

        return $trip;
    }

    /**
     * Distancia del viaje en km.
     *
     * Prioridad: odómetro del FMC920 (diferencia entre la última y la primera posición).
     * El dispositivo lo calcula internamente, así que no depende de cada cuánto se guardan
     * posiciones: sigue siendo exacto si la configuración espacia los registros.
     * Si falta o es inconsistente (negativo, por ejemplo por un reinicio del contador),
     * se usa la suma Haversine entre posiciones.
     *
     * Validado el 6 de octubre de 2026: en 4 viajes reales, odómetro, Haversine y
     * totalDistance de Traccar coincidieron con diferencias de 10 m o menos
     * (docs/piloto-fmc920.md, sección 4.6).
     */
    protected function calculateTripDistanceKm(Collection $positions): float
    {
        // getAttribute() explícito: la columna se llama "attributes", igual que la
        // propiedad interna de Eloquent, y así no hay ambigüedad.
        $startOdometer = $positions->first()->getAttribute('attributes')['odometer'] ?? null;
        $endOdometer = $positions->last()->getAttribute('attributes')['odometer'] ?? null;

        if (is_numeric($startOdometer) && is_numeric($endOdometer) && $endOdometer >= $startOdometer) {
            return round(($endOdometer - $startOdometer) / 1000, 3);
        }

        return $this->calculateDistanceKm($positions);
    }

    /**
     * Distancia total sumando la distancia Haversine entre posiciones consecutivas.
     * Respaldo de calculateTripDistanceKm() cuando no hay odómetro confiable.
     */
    protected function calculateDistanceKm(Collection $positions): float
    {
        $total = 0.0;
        $prev = null;

        foreach ($positions as $position) {
            if ($prev !== null) {
                $total += $this->distanceKm($prev, $position);
            }
            $prev = $position;
        }

        return round($total, 3);
    }

    protected function distanceKm(Position $a, Position $b): float
    {
        return $this->haversineKm($a->latitude, $a->longitude, $b->latitude, $b->longitude);
    }

    protected function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Cuenta paradas intermedias (velocidad menor a IDLE_SPEED_KMH sostenida) dentro del viaje
     * y el total de segundos detenido. No cuenta como parada el cierre del viaje mismo.
     * No usa la ignición: en unidades con la señal ruidosa, cada parpadeo cortaría la parada.
     *
     * @return array{0: int, 1: int} [stops_count, stopped_seconds]
     */
    protected function calculateStops(Collection $positions): array
    {
        $stopsCount = 0;
        $stoppedSeconds = 0;
        $stopStart = null;
        $previous = null;

        foreach ($positions as $position) {
            // Un silencio desde la posición anterior también es estar detenida
            $silence = $previous !== null && $this->isSilence($previous, $position);
            $isStopped = $position->speed < self::IDLE_SPEED_KMH || $silence;

            if ($isStopped) {
                if ($stopStart === null) {
                    $stopStart = $silence ? $previous : $position;
                }
            } else {
                if ($stopStart !== null) {
                    $duration = abs($position->device_time->diffInSeconds($stopStart->device_time));
                    if ($duration >= 30) { // ignorar paradas triviales (semáforo corto, ruido de GPS)
                        $stopsCount++;
                        $stoppedSeconds += $duration;
                    }
                    $stopStart = null;
                }
            }

            $previous = $position;
        }

        return [$stopsCount, $stoppedSeconds];
    }
}