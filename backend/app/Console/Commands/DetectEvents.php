<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Models\VehicleEvent;
use App\Models\VehicleState;
use App\Services\EventDetector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Detecta eventos con las posiciones nuevas de cada unidad.
 *
 *   php artisan events:detect                    Lo nuevo desde la última corrida (el scheduler)
 *   php artisan events:detect --from=2026-10-19  Borra y reprocesa desde ese día (hora Cancún)
 *   php artisan events:detect --vehicle=2        Solo una unidad
 */
class DetectEvents extends Command
{
    protected $signature = 'events:detect
        {--vehicle= : ID de la unidad; sin esto, todas las activas}
        {--from= : Día (YYYY-MM-DD, hora Cancún) desde el cual borrar y volver a detectar}';

    protected $description = 'Detecta eventos: zonas, visitas a lugares, exceso de velocidad, paradas largas y sin señal';

    public function handle(EventDetector $detector): int
    {
        $vehicles = Vehicle::query()
            ->when($this->option('vehicle'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('active', true))
            ->orderBy('id')
            ->get();

        if ($vehicles->isEmpty()) {
            $this->warn('No hay unidades que procesar.');
            return self::SUCCESS;
        }

        $from = null;
        if ($this->option('from')) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $this->option('from'), 'America/Cancun')?->startOfDay()->utc();
            if (!$from) {
                $this->error('Fecha inválida. Usa YYYY-MM-DD, por ejemplo 2026-10-19.');
                return self::FAILURE;
            }
        }

        // Una sola corrida a la vez, sea del scheduler o a mano: dos detectores sobre la
        // misma unidad duplicarían eventos y se bloquearían la base entre sí
        $lock = Cache::lock('events:detect', 600);
        if (!$lock->get()) {
            $this->warn('Ya hay una detección en curso (quizá la del scheduler). Intenta en un minuto.');
            return self::SUCCESS;
        }

        try {
            $rows = $this->detectAll($vehicles, $detector, $from);
        } finally {
            $lock->release();
        }

        // En la corrida automática solo se imprime si hubo algo, para no llenar el log
        if ($from || $this->output->isVerbose() || collect($rows)->sum(1) > 0) {
            $this->table(['Unidad', 'Eventos nuevos'], $rows);
        }

        return self::SUCCESS;
    }

    protected function detectAll($vehicles, EventDetector $detector, ?CarbonImmutable $from): array
    {
        $rows = [];
        foreach ($vehicles as $vehicle) {
            if ($from) {
                $this->reset($vehicle, $from);
            }

            $created = $detector->process($vehicle, now(), $from);
            $rows[] = [$vehicle->name, $created];
        }

        return $rows;
    }

    /**
     * Borra los eventos desde $from (y los que quedaron abiertos) y olvida el estado, para
     * que la unidad se procese otra vez desde ese momento.
     */
    protected function reset(Vehicle $vehicle, CarbonImmutable $from): void
    {
        VehicleEvent::where('vehicle_id', $vehicle->id)
            ->where(fn ($q) => $q->where('started_at', '>=', $from)
                ->orWhereNull('ended_at')
                ->orWhere('ended_at', '>=', $from))
            ->delete();

        VehicleState::where('vehicle_id', $vehicle->id)->delete();
    }
}