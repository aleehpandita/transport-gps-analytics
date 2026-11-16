<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Geofence;
use App\Models\GeofenceVisitState;
use App\Models\Vehicle;
use App\Services\GeofenceVisitTracker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Registra las entradas y salidas de las geocercas de cada empresa (reporte de PDI).
 *
 *   php artisan geofences:visits                        Lo nuevo (el scheduler, cada 5 minutos)
 *   php artisan geofences:visits --from=2026-11-01      Recalcula desde ese día (hora Cancún)
 *   php artisan geofences:visits --from=2026-11-01 --vehicle=2
 *
 * Recalcular hace falta después de dibujar o mover una geocerca, para que cuente los días
 * anteriores.
 */
class TrackGeofenceVisits extends Command
{
    protected $signature = 'geofences:visits
        {--vehicle= : ID de la unidad; sin esto, todas las activas}
        {--from= : Día (YYYY-MM-DD, hora Cancún) desde el cual borrar y recalcular}';

    protected $description = 'Registra entradas y salidas de las geocercas (con --from recalcula días viejos)';

    protected const TIMEZONE = 'America/Cancun';

    public function handle(GeofenceVisitTracker $tracker): int
    {
        $from = null;
        if ($this->option('from')) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $this->option('from'), self::TIMEZONE);
            if (!$parsed || $parsed->format('Y-m-d') !== $this->option('from')) {
                $this->error('Fecha inválida. Usa YYYY-MM-DD, por ejemplo 2026-11-01.');
                return self::FAILURE;
            }
            $from = $parsed->startOfDay()->utc();
        }

        $lock = Cache::lock('geofences:visits', 1800);
        if (!$lock->get()) {
            $this->warn('Ya hay un cálculo en curso. Intenta en un momento.');
            return self::SUCCESS;
        }

        try {
            $rows = $this->trackAll($tracker, $from);
        } finally {
            $lock->release();
        }

        if ($from || $this->output->isVerbose()) {
            $this->table(['Unidad', 'Entradas y salidas'], $rows);
        }

        return self::SUCCESS;
    }

    protected function trackAll(GeofenceVisitTracker $tracker, ?CarbonImmutable $from): array
    {
        $companyIds = Company::where('status', Company::STATUS_ACTIVE)->pluck('id');
        $geofences = Geofence::whereIn('company_id', $companyIds)->get()->groupBy('company_id');

        $vehicles = Vehicle::query()
            ->whereIn('company_id', $companyIds)
            ->when($this->option('vehicle'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('active', true))
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($vehicles as $vehicle) {
            $fences = $geofences->get($vehicle->company_id) ?? collect();

            // Sin geocercas no hay nada que contar. Se olvida el estado para que, cuando
            // dibujen la primera, empiece desde ese momento.
            if ($fences->isEmpty()) {
                GeofenceVisitState::where('vehicle_id', $vehicle->id)->delete();
                continue;
            }

            if ($from) {
                $tracker->reset($vehicle, $fences, $from);
            }

            $rows[] = [$vehicle->name, $tracker->process($vehicle, $fences, now(), $from)];
        }

        return $rows;
    }
}
