<?php

namespace App\Services;

use App\Models\Geofence;
use App\Models\GeofenceVisit;
use App\Models\GeofenceVisitState;
use App\Models\Position;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Registra cuándo entra y sale cada unidad de cada geocerca de su empresa, y cuánto tiempo
 * estuvo dentro. No depende de las alertas: cuenta todas las geocercas.
 *
 * - Igual que las alertas de geocerca, un cruce se confirma con 2 posiciones seguidas del
 *   otro lado (un brinco del GPS no cuenta). La hora es la de la primera de esas posiciones.
 * - Si en la primera posición que ve la unidad ya está dentro, se abre una visita marcada
 *   started_inside: la hora de entrada no se conoce.
 * - Es incremental: geofence_visit_states guarda hasta qué posición leyó y de qué lado está
 *   de cada geocerca, así que cada corrida solo lee lo nuevo.
 * - reset() permite recalcular desde un día: borra lo de ese día en adelante y reabre las
 *   visitas que venían de antes.
 *
 * Estado por geocerca: ['in' => bool, 'v' => id de la visita abierta o null, 'c' => cruce por confirmar]
 */
class GeofenceVisitTracker
{
    protected const CHUNK = 2000;
    // Posiciones seguidas del otro lado para confirmar que entró o salió
    public const CONFIRM_POSITIONS = 2;
    // Primera corrida de una unidad sin estado: solo los últimos minutos
    protected const FIRST_RUN_MINUTES = 10;

    protected Vehicle $vehicle;
    /** @var Collection<int, Geofence> */
    protected Collection $geofences;
    protected array $g = [];
    protected int $changes = 0;

    /**
     * @param Collection<int, Geofence> $geofences Geocercas de la empresa de la unidad
     * @return int Entradas y salidas registradas en esta corrida
     */
    public function process(Vehicle $vehicle, Collection $geofences, ?CarbonInterface $now = null, ?CarbonInterface $startIfNew = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $this->vehicle = $vehicle;
        $this->geofences = $geofences->keyBy('id');
        $this->changes = 0;

        $record = GeofenceVisitState::firstOrNew(['vehicle_id' => $vehicle->id]);
        // Se olvida lo de las geocercas que ya no existen
        $this->g = array_intersect_key($record->state['g'] ?? [], $this->geofences->all());

        $cursor = $record->last_device_time ?? $startIfNew ?? $now->subMinutes(self::FIRST_RUN_MINUTES);

        do {
            $positions = Position::where('vehicle_id', $vehicle->id)
                ->where('valid', true)
                ->where('device_time', '>', $cursor)
                ->orderBy('device_time')
                ->limit(self::CHUNK)
                ->get(['id', 'latitude', 'longitude', 'device_time']);

            foreach ($positions as $p) {
                $this->handle($p->device_time->getTimestamp(), (float) $p->latitude, (float) $p->longitude);
            }

            if ($positions->isNotEmpty()) {
                $cursor = $positions->last()->device_time;
            }
        } while ($positions->count() === self::CHUNK);

        $record->last_device_time = $cursor;
        $record->state = ['g' => $this->g];
        $record->save();

        return $this->changes;
    }

    /**
     * Prepara un recálculo desde $from (UTC): borra las visitas que empezaron desde ahí,
     * reabre las que venían de antes y deja el estado como estaba justo antes de $from.
     *
     * @param Collection<int, Geofence> $geofences
     */
    public function reset(Vehicle $vehicle, Collection $geofences, CarbonInterface $from): void
    {
        GeofenceVisit::where('vehicle_id', $vehicle->id)->where('entered_at', '>=', $from)->delete();

        // Dónde estaba antes de $from
        $last = Position::where('vehicle_id', $vehicle->id)
            ->where('valid', true)
            ->where('device_time', '<', $from)
            ->orderByDesc('device_time')
            ->first(['latitude', 'longitude']);

        $g = [];
        foreach ($geofences as $geofence) {
            // Una visita que empezó antes y terminó después (o sigue): vuelve a quedar abierta
            $spanning = GeofenceVisit::where('vehicle_id', $vehicle->id)
                ->where('geofence_id', $geofence->id)
                ->where('entered_at', '<', $from)
                ->where(fn ($q) => $q->whereNull('exited_at')->orWhere('exited_at', '>=', $from))
                ->orderByDesc('entered_at')
                ->first();

            if ($spanning) {
                $spanning->update(['exited_at' => null, 'duration_seconds' => null]);
                $g[$geofence->id] = ['in' => true, 'v' => $spanning->id, 'c' => null];
                continue;
            }

            if (!$last) {
                continue; // sin posiciones antes: lo decide la primera que llegue
            }

            $inside = $geofence->contains((float) $last->latitude, (float) $last->longitude);
            $g[$geofence->id] = ['in' => $inside, 'v' => null, 'c' => null];

            // Estaba dentro pero no hay visita (nunca se había calculado): empieza en $from
            if ($inside) {
                $g[$geofence->id]['v'] = $this->open($vehicle, $geofence, CarbonImmutable::instance($from)->getTimestamp(), true);
            }
        }

        GeofenceVisitState::updateOrCreate(
            ['vehicle_id' => $vehicle->id],
            ['last_device_time' => $from, 'state' => ['g' => $g]],
        );
    }

    protected function handle(int $t, float $lat, float $lng): void
    {
        foreach ($this->geofences as $id => $geofence) {
            $inside = $geofence->contains($lat, $lng);
            $s = $this->g[$id] ?? null;

            // Primera posición con esta geocerca: solo se anota de qué lado está
            if ($s === null) {
                $this->g[$id] = [
                    'in' => $inside,
                    'v' => $inside ? $this->open($this->vehicle, $geofence, $t, true) : null,
                    'c' => null,
                ];
                continue;
            }

            if ($inside === $s['in']) {
                $s['c'] = null;
                $this->g[$id] = $s;
                continue;
            }

            $c = $s['c'] ?? ['n' => 0, 't' => $t];
            $c['n']++;

            if ($c['n'] < self::CONFIRM_POSITIONS) {
                $s['c'] = $c;
                $this->g[$id] = $s;
                continue;
            }

            if ($inside) {
                $s['v'] = $this->open($this->vehicle, $geofence, $c['t'], false);
            } else {
                $this->close($s['v'], $c['t']);
                $s['v'] = null;
            }

            $s['in'] = $inside;
            $s['c'] = null;
            $this->g[$id] = $s;
            $this->changes++;
        }
    }

    protected function open(Vehicle $vehicle, Geofence $geofence, int $t, bool $startedInside): int
    {
        return GeofenceVisit::firstOrCreate(
            ['vehicle_id' => $vehicle->id, 'geofence_id' => $geofence->id, 'entered_at' => CarbonImmutable::createFromTimestamp($t)],
            ['company_id' => $geofence->company_id, 'started_inside' => $startedInside],
        )->id;
    }

    protected function close(?int $visitId, int $t): void
    {
        if (!$visitId || !($visit = GeofenceVisit::find($visitId))) {
            return; // salió sin visita abierta (la borraron): no hay nada que cerrar
        }

        $visit->exited_at = CarbonImmutable::createFromTimestamp($t);
        $visit->duration_seconds = max(0, $t - $visit->entered_at->getTimestamp());
        $visit->save();
    }
}
