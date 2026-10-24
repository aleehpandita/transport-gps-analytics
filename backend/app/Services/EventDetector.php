<?php

namespace App\Services;

use App\Models\Place;
use App\Models\Position;
use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VehicleState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detecta eventos leyendo las posiciones de cada unidad en orden:
 *
 *   zone_enter / zone_exit  Entra o sale de una zona (confirmado con N posiciones seguidas).
 *   place_visit             Llega a un lugar del catálogo (lobby, terminal) y luego se va.
 *   overspeed               Arriba del límite por N segundos. Guarda la velocidad máxima.
 *   long_stop               Detenida N minutos fuera de un lugar del catálogo.
 *   signal_lost             N minutos sin reportar.
 *
 * Es incremental: vehicle_states guarda la última posición procesada y lo que está en
 * curso (zona actual, visita abierta, exceso en curso...). Cada corrida solo lee lo nuevo,
 * así que cuesta lo mismo con una semana de datos que con un año.
 *
 * No depende de cómo llegan las posiciones: hoy las trae traccar:sync-positions cada 2 min;
 * mañana pueden llegar una por una desde Traccar y se llama a process() igual.
 */
class EventDetector
{
    // Posiciones por lectura: acota la memoria aunque una unidad traiga días atrasados
    protected const CHUNK = 2000;
    // Posiciones por transacción: corta, para no bloquear a los demás procesos
    protected const TRANSACTION_SIZE = 200;
    // Si entre dos posiciones con exceso pasan más de esto, son dos excesos distintos
    protected const OVERSPEED_GAP_SECONDS = 120;
    // Solo se vuelve a calcular la zona si la unidad se movió esto (el cálculo es lo caro)
    protected const ZONE_RECHECK_METERS = 50;
    // Visitas más cortas que esto son pasadas por enfrente, no visitas
    protected const MIN_VISIT_SECONDS = 60;

    protected ?Collection $places = null;
    protected array $config;

    public function __construct(protected ZoneMatchingService $zones)
    {
        $this->config = config('alerts');
    }

    /**
     * Procesa las posiciones nuevas de la unidad y revisa lo que depende del reloj
     * (sin señal, parada larga sin posiciones nuevas).
     *
     * @return int Eventos creados
     */
    public function process(Vehicle $vehicle, ?CarbonInterface $now = null, ?CarbonInterface $startIfNew = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());

        $record = VehicleState::firstOrNew(['vehicle_id' => $vehicle->id]);
        $s = $record->state ?? [];
        // Primera vez: arranca donde se pidió (o al inicio del día) para no llenar de
        // eventos viejos. Para procesar el pasado está events:detect --from.
        $cursor = $record->last_device_time ?? $startIfNew ?? $now->tz('America/Cancun')->startOfDay()->utc();
        $created = 0;

        do {
            $positions = Position::where('vehicle_id', $vehicle->id)
                ->where('valid', true)
                ->where('device_time', '>', $cursor)
                ->orderBy('device_time')
                ->limit(self::CHUNK)
                ->get(['id', 'latitude', 'longitude', 'speed', 'device_time']);

            // Transacciones cortas: agrupar escrituras es mucho más rápido en SQLite, pero
            // mientras dura una transacción nadie más puede escribir (sync, viajes)
            foreach ($positions->chunk(self::TRANSACTION_SIZE) as $batch) {
                DB::transaction(function () use ($batch, $vehicle, &$s, &$created) {
                    foreach ($batch as $p) {
                        $created += $this->handle($vehicle, $p, $s);
                    }
                }, 3);
            }

            if ($positions->isNotEmpty()) {
                $cursor = $positions->last()->device_time;
            }
        } while ($positions->count() === self::CHUNK);

        $created += $this->checkClock($vehicle, $now, $s);

        $record->last_device_time = $cursor;
        $record->state = $s;
        $record->save();

        return $created;
    }

    /**
     * Una posición: actualiza el estado y crea o cierra eventos.
     */
    protected function handle(Vehicle $vehicle, Position $p, array &$s): int
    {
        $t = $p->device_time->getTimestamp();
        $lat = (float) $p->latitude;
        $lng = (float) $p->longitude;
        $speed = (float) $p->speed;
        $created = 0;

        $created += $this->signal($vehicle, $t, $s);
        $created += $this->zone($vehicle, $t, $lat, $lng, $s);
        $created += $this->place($vehicle, $t, $lat, $lng, $speed, $s);
        $created += $this->overspeed($vehicle, $t, $lat, $lng, $speed, $s);
        $created += $this->stop($vehicle, $t, $lat, $lng, $speed, $s);

        $s['last'] = ['t' => $t, 'lat' => $lat, 'lng' => $lng, 'speed' => $speed];

        return $created;
    }

    /**
     * Sin señal: si había un evento abierto, la señal volvió y se cierra. Si no, y el hueco
     * desde la posición anterior pasa el umbral, se registra ya cerrado (pasó en el pasado).
     */
    protected function signal(Vehicle $vehicle, int $t, array &$s): int
    {
        $last = $s['last'] ?? null;
        if (!$last) {
            return 0;
        }

        if ($eventId = $s['signal_event_id'] ?? null) {
            $this->close($eventId, $t, ['gap_minutes' => intdiv($t - $last['t'], 60)]);
            $s['signal_event_id'] = null;
            return 0;
        }

        $gap = $t - $last['t'];
        $threshold = $this->signalThresholdSeconds($last);
        if ($threshold === null || $gap < $threshold) {
            return 0;
        }

        $this->create($vehicle, VehicleEvent::SIGNAL_LOST, $last['t'], $last['lat'], $last['lng'], $s, [
            'ended_at' => $this->time($t),
            'data' => ['gap_minutes' => intdiv($gap, 60)],
        ]);

        return 1;
    }

    /**
     * Zona: la nueva se confirma con N posiciones seguidas dentro de ella.
     */
    protected function zone(Vehicle $vehicle, int $t, float $lat, float $lng, array &$s): int
    {
        $check = $s['zone_check'] ?? null;
        if (!$check || $this->meters($lat, $lng, $check['lat'], $check['lng']) > self::ZONE_RECHECK_METERS) {
            $s['zone_seen'] = $this->zones->resolve($lat, $lng)?->id;
            $s['zone_check'] = ['lat' => $lat, 'lng' => $lng];
        }
        $seen = $s['zone_seen'];

        // Primera posición de la unidad: se toma su zona sin evento
        if (!array_key_exists('zone_id', $s)) {
            $s['zone_id'] = $seen;
            return 0;
        }

        if ($seen === $s['zone_id']) {
            $s['zone_candidate'] = null;
            return 0;
        }

        $candidate = $s['zone_candidate'] ?? null;
        if ($candidate && $candidate['id'] === $seen) {
            $candidate['count']++;
        } else {
            $candidate = ['id' => $seen, 'count' => 1, 't' => $t, 'lat' => $lat, 'lng' => $lng];
        }

        if ($candidate['count'] < $this->config['zone_confirm_positions']) {
            $s['zone_candidate'] = $candidate;
            return 0;
        }

        // Confirmada: sale de la anterior y entra a la nueva, en el punto donde cruzó.
        // Son instantáneos: empiezan y terminan en el mismo momento.
        $created = 0;
        $at = $this->time($candidate['t']);
        if ($s['zone_id'] !== null) {
            $this->create($vehicle, VehicleEvent::ZONE_EXIT, $candidate['t'], $candidate['lat'], $candidate['lng'], $s, [
                'zone_id' => $s['zone_id'],
                'ended_at' => $at,
            ]);
            $created++;
        }
        if ($seen !== null) {
            $this->create($vehicle, VehicleEvent::ZONE_ENTER, $candidate['t'], $candidate['lat'], $candidate['lng'], $s, [
                'zone_id' => $seen,
                'ended_at' => $at,
            ]);
            $created++;
        }

        $s['zone_id'] = $seen;
        $s['zone_candidate'] = null;

        return $created;
    }

    /**
     * Visita a un lugar: empieza al entrar a su radio despacio y termina al alejarse
     * place_leave_factor veces el radio. La hora de salida es la primera posición fuera
     * del radio, no la del punto lejano.
     */
    protected function place(Vehicle $vehicle, int $t, float $lat, float $lng, float $speed, array &$s): int
    {
        if ($visit = $s['visit'] ?? null) {
            $place = $this->places()->firstWhere('id', $visit['place_id']);
            $meters = $place ? $this->meters($lat, $lng, $place->latitude, $place->longitude) : INF;

            if ($place && $meters <= $place->radius_m) {
                $visit['left_t'] = null;
                $s['visit'] = $visit;
                return 0;
            }

            $visit['left_t'] ??= $t;

            if ($place && $meters <= $place->radius_m * $this->config['place_leave_factor']) {
                $s['visit'] = $visit;
                return 0;
            }

            $dwell = $visit['left_t'] - $visit['t'];
            if ($dwell < self::MIN_VISIT_SECONDS) {
                VehicleEvent::whereKey($visit['event_id'])->delete();
            } else {
                $this->close($visit['event_id'], $visit['left_t'], ['dwell_seconds' => $dwell]);
            }
            $s['visit'] = null;
        }

        if ($speed >= $this->config['place_slow_kmh'] || !($place = $this->placeAt($lat, $lng))) {
            return 0;
        }

        $event = $this->create($vehicle, VehicleEvent::PLACE_VISIT, $t, $lat, $lng, $s, ['place_id' => $place->id]);
        $s['visit'] = ['event_id' => $event->id, 'place_id' => $place->id, 't' => $t, 'left_t' => null];

        return 1;
    }

    /**
     * Exceso de velocidad: el evento se crea en cuanto se sostiene overspeed_seconds (para
     * avisar al momento) y se cierra al bajar del límite con la velocidad máxima.
     */
    protected function overspeed(Vehicle $vehicle, int $t, float $lat, float $lng, float $speed, array &$s): int
    {
        $over = $s['over'] ?? null;

        if ($over && $t - $over['last_t'] > self::OVERSPEED_GAP_SECONDS) {
            $this->closeOverspeed($over);
            $over = null;
        }

        if ($speed <= $this->config['overspeed_kmh']) {
            if ($over) {
                $this->closeOverspeed($over);
            }
            $s['over'] = null;
            return 0;
        }

        $over ??= ['t' => $t, 'lat' => $lat, 'lng' => $lng, 'max' => $speed, 'event_id' => null];
        $over['last_t'] = $t;
        $over['max'] = max($over['max'], $speed);

        $created = 0;
        if (!$over['event_id'] && $t - $over['t'] >= $this->config['overspeed_seconds']) {
            $event = $this->create($vehicle, VehicleEvent::OVERSPEED, $over['t'], $over['lat'], $over['lng'], $s, [
                'value' => $over['max'],
                'data' => ['limit_kmh' => $this->config['overspeed_kmh']],
            ]);
            $over['event_id'] = $event->id;
            $created = 1;
        }

        $s['over'] = $over;

        return $created;
    }

    protected function closeOverspeed(array $over): void
    {
        if (!$over['event_id']) {
            return;
        }

        VehicleEvent::whereKey($over['event_id'])->update([
            'ended_at' => $this->time($over['last_t']),
            'value' => round($over['max'], 2),
        ]);
        $this->mergeData($over['event_id'], ['duration_seconds' => $over['last_t'] - $over['t']]);
    }

    /**
     * Parada: empieza con la primera posición lenta y se reinicia si se mueve más de
     * long_stop_radius_m del punto donde se detuvo (avanzar en una fila no es parada).
     */
    protected function stop(Vehicle $vehicle, int $t, float $lat, float $lng, float $speed, array &$s): int
    {
        $stop = $s['stop'] ?? null;

        if ($speed >= $this->config['idle_speed_kmh']) {
            if ($stop && $stop['event_id']) {
                $this->close($stop['event_id'], $t, ['minutes' => intdiv($t - $stop['t'], 60)]);
            }
            $s['stop'] = null;
            return 0;
        }

        if (!$stop || $this->meters($lat, $lng, $stop['lat'], $stop['lng']) > $this->config['long_stop_radius_m']) {
            if ($stop && $stop['event_id']) {
                $this->close($stop['event_id'], $t, ['minutes' => intdiv($t - $stop['t'], 60)]);
            }
            $stop = ['t' => $t, 'lat' => $lat, 'lng' => $lng, 'event_id' => null];
        }

        $s['stop'] = $stop;

        return $this->checkLongStop($vehicle, $t, $s);
    }

    protected function checkLongStop(Vehicle $vehicle, int $t, array &$s): int
    {
        $stop = $s['stop'] ?? null;
        if (!$stop || $stop['event_id'] || $t - $stop['t'] < $this->config['long_stop_minutes'] * 60) {
            return 0;
        }

        // En un lobby, la terminal o la base, o esperando vuelos cerca del aeropuerto,
        // detenerse es parte del servicio
        if ($this->placeAt($stop['lat'], $stop['lng']) || $this->nearAirport($stop['lat'], $stop['lng'])) {
            $s['stop']['event_id'] = -1; // ya revisada: no volver a buscar el lugar
            return 0;
        }

        $event = $this->create($vehicle, VehicleEvent::LONG_STOP, $stop['t'], $stop['lat'], $stop['lng'], $s);
        $s['stop']['event_id'] = $event->id;

        return 1;
    }

    /**
     * Lo que se detecta por el paso del tiempo, aunque no lleguen posiciones nuevas.
     */
    protected function checkClock(Vehicle $vehicle, CarbonImmutable $now, array &$s): int
    {
        $last = $s['last'] ?? null;
        if (!$last) {
            return 0;
        }

        $created = 0;
        $t = $now->getTimestamp();

        // Un exceso sin posiciones nuevas ya terminó
        if (($over = $s['over'] ?? null) && $t - $over['last_t'] > self::OVERSPEED_GAP_SECONDS) {
            $this->closeOverspeed($over);
            $s['over'] = null;
        }

        $threshold = $this->signalThresholdSeconds($last);
        if (empty($s['signal_event_id']) && $threshold !== null && $t - $last['t'] >= $threshold) {
            $event = $this->create($vehicle, VehicleEvent::SIGNAL_LOST, $last['t'], $last['lat'], $last['lng'], $s);
            $s['signal_event_id'] = $event->id;
            $created++;
        }

        // Si dejó de reportar detenida, se asume que sigue ahí (igual que el Trip Builder
        // con los silencios), salvo que ya sea "sin señal": entonces no se sabe.
        if (empty($s['signal_event_id'])) {
            $created += $this->checkLongStop($vehicle, $t, $s);
        }

        return $created;
    }

    /**
     * Segundos sin reportar que cuentan como "sin señal", según cómo iba la unidad.
     * null = no avisar (detenida, con signal_lost_stopped_minutes en 0).
     */
    protected function signalThresholdSeconds(array $last): ?int
    {
        $minutes = $last['speed'] >= $this->config['idle_speed_kmh']
            ? $this->config['signal_lost_moving_minutes']
            : $this->config['signal_lost_stopped_minutes'];

        return $minutes > 0 ? $minutes * 60 : null;
    }

    /**
     * Crea el evento (o regresa el que ya existía, si se reprocesa). Toma la zona actual
     * de la unidad, salvo que se indique otra.
     */
    protected function create(Vehicle $vehicle, string $type, int $t, float $lat, float $lng, array $s, array $extra = []): VehicleEvent
    {
        return VehicleEvent::firstOrCreate(
            ['vehicle_id' => $vehicle->id, 'type' => $type, 'started_at' => $this->time($t)],
            [
                'latitude' => $lat,
                'longitude' => $lng,
                'zone_id' => $extra['zone_id'] ?? ($s['zone_id'] ?? null),
                'place_id' => $extra['place_id'] ?? null,
                'value' => $extra['value'] ?? null,
                'ended_at' => $extra['ended_at'] ?? null,
                'data' => $extra['data'] ?? null,
            ]
        );
    }

    protected function close(int $eventId, int $t, array $data = []): void
    {
        if ($eventId <= 0) {
            return;
        }

        VehicleEvent::whereKey($eventId)->update(['ended_at' => $this->time($t)]);
        $this->mergeData($eventId, $data);
    }

    protected function mergeData(int $eventId, array $data): void
    {
        $event = VehicleEvent::find($eventId);
        if ($event && $data) {
            $event->data = array_merge($event->data ?? [], $data);
            $event->save();
        }
    }

    protected function placeAt(float $lat, float $lng): ?Place
    {
        $best = null;
        $bestMeters = INF;

        foreach ($this->places() as $place) {
            $meters = $this->meters($lat, $lng, $place->latitude, $place->longitude);
            if ($meters <= $place->radius_m && $meters < $bestMeters) {
                $best = $place;
                $bestMeters = $meters;
            }
        }

        return $best;
    }

    protected function nearAirport(float $lat, float $lng): bool
    {
        return $this->places()
            ->where('type', 'airport_terminal')
            ->contains(fn (Place $p) => $this->meters($lat, $lng, $p->latitude, $p->longitude) <= $this->config['long_stop_airport_m']);
    }

    /**
     * @return Collection<int, Place>
     */
    protected function places(): Collection
    {
        return $this->places ??= Place::where('is_active', true)->get(['id', 'name', 'type', 'latitude', 'longitude', 'radius_m'])
            ->each(function (Place $p) {
                $p->latitude = (float) $p->latitude;
                $p->longitude = (float) $p->longitude;
            });
    }

    protected function time(int $timestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, config('app.timezone'));
    }

    protected function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}