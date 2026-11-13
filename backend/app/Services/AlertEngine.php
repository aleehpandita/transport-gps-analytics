<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Geofence;
use App\Models\Position;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Revisa las reglas de alerta de cada empresa contra las posiciones de sus unidades.
 *
 *   overspeed       Arriba de speed_kmh por al menos seconds segundos. Un aviso por exceso.
 *   geofence_exit   Sale de la geocerca (confirmado con 2 posiciones seguidas afuera).
 *   geofence_enter  Entra a la geocerca (2 posiciones seguidas adentro).
 *   long_stop       Detenida minutes minutos en el mismo punto. Con except_in_geofences no
 *                   avisa si está dentro de una geocerca de la empresa (la base, un hotel).
 *   signal_lost     minutes minutos sin reportar, si iba en movimiento o con el motor
 *                   encendido. Estacionado el FMC920 se duerme y deja de reportar: eso es normal.
 *   after_hours     Se mueve (10 km/h o más, 2 posiciones seguidas) dentro del horario de la
 *                   regla. El horario de la regla es el "fuera de horario" (por ejemplo, de noche).
 *
 * Todas las reglas respetan sus días y horario y su "no repetir antes de". Una situación que
 * dura (un exceso, una parada) avisa una sola vez; si empieza fuera del horario de la regla
 * y sigue cuando el horario empieza, avisa en ese momento.
 *
 * Es incremental como el EventDetector: alert_states guarda hasta qué posición leyó y lo que
 * está en curso por cada regla, así que cada corrida solo lee lo nuevo.
 */
class AlertEngine
{
    protected const CHUNK = 2000;
    // Debajo de esta velocidad está detenida (igual que el Trip Builder)
    protected const IDLE_KMH = 3;
    // Moverse menos que esto del punto donde se detuvo sigue siendo la misma parada
    protected const STOP_RADIUS_M = 60;
    // Más de esto entre dos posiciones con exceso: son dos excesos distintos
    protected const OVERSPEED_GAP_SECONDS = 120;
    // Posiciones seguidas del otro lado para confirmar que entró o salió de una geocerca
    protected const GEOFENCE_CONFIRM_POSITIONS = 2;
    // Movimiento fuera de horario: velocidad y posiciones seguidas para contarlo
    protected const MOVING_KMH = 10;
    protected const MOVING_CONFIRM_POSITIONS = 2;
    // Sin moverse esto, el movimiento terminó: el siguiente es otro
    protected const MOVEMENT_END_SECONDS = 300;
    // Primera corrida de una unidad: solo mira los últimos minutos, no el pasado
    protected const FIRST_RUN_MINUTES = 10;

    protected Vehicle $vehicle;
    /** @var Collection<int, Geofence> */
    protected Collection $geofences;
    /** @var Collection<int, Alert> */
    protected Collection $created;
    protected array $s = [];

    /**
     * @param Collection<int, AlertRule> $rules Reglas activas de la empresa que aplican a esta unidad
     * @param Collection<int, Geofence> $geofences Geocercas de la empresa, por id
     * @return Collection<int, Alert> Avisos nuevos
     */
    public function process(
        Vehicle $vehicle,
        Collection $rules,
        Collection $geofences,
        ?CarbonInterface $now = null,
        ?CarbonInterface $startIfNew = null,
    ): Collection {
        $now = CarbonImmutable::instance($now ?? now());
        $this->vehicle = $vehicle;
        $this->geofences = $geofences;
        $this->created = collect();

        $record = AlertState::firstOrNew(['vehicle_id' => $vehicle->id]);
        $this->s = $record->state ?? [];
        $this->s['rules'] = array_intersect_key($this->s['rules'] ?? [], $rules->keyBy('id')->all());
        $this->s['fired'] = array_intersect_key($this->s['fired'] ?? [], $rules->keyBy('id')->all());

        $cursor = $record->last_device_time ?? $startIfNew ?? $now->subMinutes(self::FIRST_RUN_MINUTES);

        do {
            $positions = Position::where('vehicle_id', $vehicle->id)
                ->where('valid', true)
                ->where('device_time', '>', $cursor)
                ->orderBy('device_time')
                ->limit(self::CHUNK)
                ->get(['id', 'latitude', 'longitude', 'speed', 'ignition', 'device_time']);

            foreach ($positions as $p) {
                $this->handle($rules, $p);
            }

            if ($positions->isNotEmpty()) {
                $cursor = $positions->last()->device_time;
            }
        } while ($positions->count() === self::CHUNK);

        $this->checkClock($rules, $now->getTimestamp());

        $record->last_device_time = $cursor;
        $record->state = $this->s;
        $record->save();

        return $this->created;
    }

    protected function handle(Collection $rules, Position $p): void
    {
        $t = $p->device_time->getTimestamp();
        $point = [
            't' => $t,
            'lat' => (float) $p->latitude,
            'lng' => (float) $p->longitude,
            'speed' => (float) $p->speed,
            'ignition' => $p->ignition,
        ];

        foreach ($rules as $rule) {
            $r = $this->s['rules'][$rule->id] ?? [];

            match ($rule->type) {
                AlertRule::OVERSPEED => $this->overspeed($rule, $point, $r),
                AlertRule::GEOFENCE_EXIT, AlertRule::GEOFENCE_ENTER => $this->geofence($rule, $point, $r),
                AlertRule::LONG_STOP => $this->longStop($rule, $point, $r),
                AlertRule::SIGNAL_LOST => $this->signalGap($rule, $point, $r),
                AlertRule::AFTER_HOURS => $this->afterHours($rule, $point, $r),
                default => null,
            };

            $this->s['rules'][$rule->id] = $r;
        }

        $this->s['last'] = $point;
    }

    /**
     * Lo que depende del reloj aunque no lleguen posiciones: sin señal, parada larga de una
     * unidad que se durmió estacionada, excesos y movimientos que ya terminaron.
     */
    protected function checkClock(Collection $rules, int $now): void
    {
        $last = $this->s['last'] ?? null;
        if (!$last) {
            return;
        }

        foreach ($rules as $rule) {
            $r = $this->s['rules'][$rule->id] ?? [];

            switch ($rule->type) {
                case AlertRule::OVERSPEED:
                    if (($ep = $r['ep'] ?? null) && $now - $ep['last_t'] > self::OVERSPEED_GAP_SECONDS) {
                        $this->closeOverspeed($ep);
                        $r['ep'] = null;
                    }
                    break;

                case AlertRule::LONG_STOP:
                    // Si dejó de reportar detenida, sigue ahí
                    if ($last['speed'] < self::IDLE_KMH) {
                        $this->checkLongStop($rule, $now, $r);
                    }
                    break;

                case AlertRule::SIGNAL_LOST:
                    $this->signalClock($rule, $last, $now, $r);
                    break;

                case AlertRule::AFTER_HOURS:
                    if (($ep = $r['ep'] ?? null) && $now - $ep['last_move'] >= self::MOVEMENT_END_SECONDS) {
                        $r['ep'] = null;
                    }
                    break;
            }

            $this->s['rules'][$rule->id] = $r;
        }
    }

    // ---------------------------------------------------------------- Exceso de velocidad

    protected function overspeed(AlertRule $rule, array $p, array &$r): void
    {
        $limit = (float) $rule->setting('speed_kmh', 100);
        $seconds = (int) $rule->setting('seconds', 30);
        $ep = $r['ep'] ?? null;

        if ($ep && $p['t'] - $ep['last_t'] > self::OVERSPEED_GAP_SECONDS) {
            $this->closeOverspeed($ep);
            $ep = null;
        }

        if ($p['speed'] <= $limit) {
            if ($ep) {
                $this->closeOverspeed($ep);
            }
            $r['ep'] = null;
            return;
        }

        $ep ??= ['t' => $p['t'], 'lat' => $p['lat'], 'lng' => $p['lng'], 'max' => $p['speed'], 'alert_id' => null, 'done' => false];
        $ep['last_t'] = $p['t'];
        $ep['max'] = max($ep['max'], $p['speed']);

        if (!$ep['done'] && $p['t'] - $ep['t'] >= $seconds) {
            $max = round($ep['max']);
            $result = $this->fire($rule, $p['t'], $ep['t'], $ep['lat'], $ep['lng'], $max,
                "{$this->vehicle->name} iba a {$max} km/h (límite " . round($limit) . ' km/h)',
                ['limit_kmh' => $limit]);
            if ($result instanceof Alert) {
                $ep['alert_id'] = $result->id;
            }
            $ep['done'] = $result !== 'inactive';
        }

        $r['ep'] = $ep;
    }

    /**
     * Al terminar el exceso se guarda la velocidad máxima y cuánto duró.
     */
    protected function closeOverspeed(array $ep): void
    {
        if (!$ep['alert_id'] || !($alert = Alert::find($ep['alert_id']))) {
            return;
        }

        $max = round($ep['max']);
        $limit = round((float) ($alert->data['limit_kmh'] ?? 0));
        $alert->value = $max;
        $alert->message = "{$this->vehicle->name} llegó a {$max} km/h (límite {$limit} km/h)";
        $alert->data = array_merge($alert->data ?? [], ['duration_seconds' => $ep['last_t'] - $ep['t']]);
        $alert->save();
    }

    // ---------------------------------------------------------------- Geocercas

    protected function geofence(AlertRule $rule, array $p, array &$r): void
    {
        $geofence = $this->geofences->get((int) $rule->setting('geofence_id'));
        if (!$geofence) {
            return;
        }

        $inside = $geofence->contains($p['lat'], $p['lng']);

        // Primera posición: solo se anota de qué lado está
        if (!array_key_exists('inside', $r)) {
            $r['inside'] = $inside;
            return;
        }

        if ($inside === $r['inside']) {
            $r['candidate'] = null;
            return;
        }

        $candidate = $r['candidate'] ?? ['count' => 0, 't' => $p['t'], 'lat' => $p['lat'], 'lng' => $p['lng']];
        $candidate['count']++;

        if ($candidate['count'] < self::GEOFENCE_CONFIRM_POSITIONS) {
            $r['candidate'] = $candidate;
            return;
        }

        $r['inside'] = $inside;
        $r['candidate'] = null;

        $exit = $rule->type === AlertRule::GEOFENCE_EXIT;
        if ($exit === $inside) {
            return; // cruzó en el sentido que esta regla no vigila
        }

        $message = $exit
            ? "{$this->vehicle->name} salió de la geocerca «{$geofence->name}»"
            : "{$this->vehicle->name} entró a la geocerca «{$geofence->name}»";

        $this->fire($rule, $candidate['t'], $candidate['t'], $candidate['lat'], $candidate['lng'], null, $message,
            ['geofence_id' => $geofence->id, 'geofence' => $geofence->name]);
    }

    // ---------------------------------------------------------------- Parada larga

    protected function longStop(AlertRule $rule, array $p, array &$r): void
    {
        $stop = $r['stop'] ?? null;

        if ($p['speed'] >= self::IDLE_KMH) {
            $r['stop'] = null;
            return;
        }

        if (!$stop || Geofence::meters($p['lat'], $p['lng'], $stop['lat'], $stop['lng']) > self::STOP_RADIUS_M) {
            $stop = ['t' => $p['t'], 'lat' => $p['lat'], 'lng' => $p['lng'], 'done' => false];
        }

        $r['stop'] = $stop;
        $this->checkLongStop($rule, $p['t'], $r);
    }

    protected function checkLongStop(AlertRule $rule, int $t, array &$r): void
    {
        $stop = $r['stop'] ?? null;
        $minutes = (int) $rule->setting('minutes', 30);
        if (!$stop || $stop['done'] || $t - $stop['t'] < $minutes * 60) {
            return;
        }

        // En la base o en un hotel que el cliente marcó con una geocerca, esperar es normal
        if ($rule->setting('except_in_geofences') && $this->insideAnyGeofence($stop['lat'], $stop['lng'])) {
            $r['stop']['done'] = true;
            return;
        }

        $elapsed = intdiv($t - $stop['t'], 60);
        $result = $this->fire($rule, $t, $t, $stop['lat'], $stop['lng'], $elapsed,
            "{$this->vehicle->name} lleva " . $this->minutesLabel($elapsed) . ' detenida en el mismo punto',
            ['stopped_since' => CarbonImmutable::createFromTimestamp($stop['t'])->toIso8601String()]);

        $r['stop']['done'] = $result !== 'inactive';
    }

    protected function insideAnyGeofence(float $lat, float $lng): bool
    {
        return $this->geofences->contains(fn (Geofence $g) => $g->contains($lat, $lng));
    }

    // ---------------------------------------------------------------- Sin señal

    /**
     * Llegó una posición después de un hueco: si el hueco pasó el límite y no se avisó ya
     * (por ejemplo, porque los datos llegaron atrasados), se avisa ahora.
     */
    protected function signalGap(AlertRule $rule, array $p, array &$r): void
    {
        $last = $this->s['last'] ?? null;
        if (!$last || !$this->wasRunning($last)) {
            return;
        }

        $minutes = (int) $rule->setting('minutes', 15);
        $gap = $p['t'] - $last['t'];
        if ($gap < $minutes * 60 || ($r['notified_for'] ?? null) === $last['t']) {
            return;
        }

        $at = $last['t'] + $minutes * 60;
        $result = $this->fire($rule, $at, $at, $last['lat'], $last['lng'], intdiv($gap, 60),
            "{$this->vehicle->name} estuvo " . $this->minutesLabel(intdiv($gap, 60)) . ' sin reportar',
            ['last_report' => CarbonImmutable::createFromTimestamp($last['t'])->toIso8601String()]);

        if ($result !== 'inactive') {
            $r['notified_for'] = $last['t'];
        }
    }

    protected function signalClock(AlertRule $rule, array $last, int $now, array &$r): void
    {
        $minutes = (int) $rule->setting('minutes', 15);
        if (!$this->wasRunning($last) || $now - $last['t'] < $minutes * 60 || ($r['notified_for'] ?? null) === $last['t']) {
            return;
        }

        $elapsed = intdiv($now - $last['t'], 60);
        $result = $this->fire($rule, $now, $last['t'] + $minutes * 60, $last['lat'], $last['lng'], $elapsed,
            "{$this->vehicle->name} lleva " . $this->minutesLabel($elapsed) . ' sin reportar',
            ['last_report' => CarbonImmutable::createFromTimestamp($last['t'])->toIso8601String()]);

        if ($result !== 'inactive') {
            $r['notified_for'] = $last['t'];
        }
    }

    /**
     * Iba en movimiento o con el motor encendido. Sin cable de ignición (null) solo cuenta
     * la velocidad.
     */
    protected function wasRunning(array $last): bool
    {
        return $last['speed'] >= self::IDLE_KMH || $last['ignition'] === true;
    }

    // ---------------------------------------------------------------- Fuera de horario

    protected function afterHours(AlertRule $rule, array $p, array &$r): void
    {
        $ep = $r['ep'] ?? null;
        if ($ep && $p['t'] - $ep['last_move'] >= self::MOVEMENT_END_SECONDS) {
            $ep = null;
        }

        if ($p['speed'] < self::MOVING_KMH) {
            $r['fast'] = 0;
            $r['ep'] = $ep;
            return;
        }

        // Las posiciones rápidas cuentan seguidas: después de un silencio se empieza de cero
        if ($p['t'] - ($r['fast_t'] ?? 0) > self::OVERSPEED_GAP_SECONDS) {
            $r['fast'] = 0;
        }
        $r['fast'] = ($r['fast'] ?? 0) + 1;
        $r['fast_t'] = $p['t'];
        if (!$ep && $r['fast'] < self::MOVING_CONFIRM_POSITIONS) {
            $r['ep'] = null;
            return;
        }

        $ep ??= ['t' => $p['t'], 'done' => false];
        $ep['last_move'] = $p['t'];

        if (!$ep['done']) {
            $speed = round($p['speed']);
            $result = $this->fire($rule, $p['t'], $p['t'], $p['lat'], $p['lng'], $speed,
                "{$this->vehicle->name} se está moviendo fuera de horario ({$speed} km/h)");
            $ep['done'] = $result !== 'inactive';
        }

        $r['ep'] = $ep;
    }

    // ---------------------------------------------------------------- Aviso

    /**
     * Crea el aviso si la regla está vigente en $checkAt y no se avisó hace poco.
     *
     * @return Alert|'inactive'|'cooldown' El aviso, o por qué no salió. 'inactive' (fuera de
     *   su horario) deja la situación pendiente; 'cooldown' la da por atendida.
     */
    protected function fire(
        AlertRule $rule,
        int $checkAt,
        int $triggeredAt,
        float $lat,
        float $lng,
        int|float|null $value,
        string $message,
        array $data = [],
    ): Alert|string {
        if (!$rule->isActiveAt($checkAt)) {
            return 'inactive';
        }

        $lastFired = $this->s['fired'][$rule->id] ?? null;
        if ($rule->repeat_minutes > 0 && $lastFired !== null && $triggeredAt - $lastFired < $rule->repeat_minutes * 60) {
            return 'cooldown';
        }

        $alert = Alert::firstOrCreate(
            [
                'alert_rule_id' => $rule->id,
                'vehicle_id' => $this->vehicle->id,
                'triggered_at' => CarbonImmutable::createFromTimestamp($triggeredAt, config('app.timezone')),
            ],
            [
                'company_id' => $rule->company_id,
                'type' => $rule->type,
                'latitude' => $lat,
                'longitude' => $lng,
                'value' => $value,
                'message' => $message,
                'data' => $data ?: null,
            ]
        );

        $this->s['fired'][$rule->id] = $triggeredAt;
        if ($alert->wasRecentlyCreated) {
            $this->created->push($alert);
        }

        return $alert;
    }

    protected function minutesLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m ? "{$h} h {$m} min" : "{$h} h";
    }
}
