<?php

namespace App\Console\Commands;

use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AlertState;
use App\Models\Company;
use App\Models\Geofence;
use App\Models\Vehicle;
use App\Services\AlertEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Revisa las alertas de todas las empresas con las posiciones nuevas y manda los correos.
 *
 *   php artisan alerts:check                              Lo nuevo (el scheduler, cada minuto)
 *   php artisan alerts:check --from=2026-11-11 --no-mail  Prueba: borra y repasa desde ese día
 *   php artisan alerts:check --vehicle=2                  Solo una unidad
 */
class CheckAlerts extends Command
{
    protected $signature = 'alerts:check
        {--vehicle= : ID de la unidad; sin esto, todas las activas con reglas}
        {--from= : Día (YYYY-MM-DD, hora Cancún) desde el cual borrar los avisos y repasar}
        {--no-mail : No mandar correos (para pruebas)}';

    protected $description = 'Revisa las alertas configuradas por cada empresa y manda los avisos';

    // Un aviso más viejo que esto (datos que llegaron muy atrasados, un repaso con --from)
    // queda en la bandeja pero no se manda por correo
    protected const EMAIL_MAX_AGE_HOURS = 6;

    public function handle(AlertEngine $engine): int
    {
        $from = null;
        if ($this->option('from')) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', $this->option('from'), AlertRule::TIMEZONE)?->startOfDay()->utc();
            if (!$from) {
                $this->error('Fecha inválida. Usa YYYY-MM-DD, por ejemplo 2026-11-11.');
                return self::FAILURE;
            }
        }

        $lock = Cache::lock('alerts:check', 600);
        if (!$lock->get()) {
            $this->warn('Ya hay una revisión en curso. Intenta en un minuto.');
            return self::SUCCESS;
        }

        try {
            $rows = $this->checkAll($engine, $from);
        } finally {
            $lock->release();
        }

        if ($from || $this->output->isVerbose() || collect($rows)->sum(1) > 0) {
            $this->table(['Unidad', 'Avisos nuevos'], $rows);
        }

        return self::SUCCESS;
    }

    protected function checkAll(AlertEngine $engine, ?CarbonImmutable $from): array
    {
        $companyIds = Company::where('status', Company::STATUS_ACTIVE)->pluck('id');
        $rules = AlertRule::where('active', true)->whereIn('company_id', $companyIds)->get()->groupBy('company_id');
        $geofences = Geofence::whereIn('company_id', $rules->keys())->get()->groupBy('company_id');

        $vehicles = Vehicle::query()
            ->whereNotNull('company_id')
            ->when($this->option('vehicle'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('active', true))
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($vehicles as $vehicle) {
            $vehicleRules = ($rules->get($vehicle->company_id) ?? collect())
                ->filter(fn (AlertRule $rule) => $rule->appliesTo($vehicle->id))
                ->values();

            if ($from) {
                $this->reset($vehicle, $from);
            }

            // Sin reglas no hay nada que vigilar. Se olvida el estado para que, cuando le
            // pongan una regla, empiece desde ese momento y no repase horas viejas.
            if ($vehicleRules->isEmpty()) {
                AlertState::where('vehicle_id', $vehicle->id)->delete();
                continue;
            }

            $created = $engine->process(
                $vehicle,
                $vehicleRules,
                ($geofences->get($vehicle->company_id) ?? collect())->keyBy('id'),
                now(),
                $from,
            );

            $this->notify($created);
            $rows[] = [$vehicle->name, $created->count()];
        }

        return $rows;
    }

    /**
     * @param Collection<int, Alert> $alerts
     */
    protected function notify(Collection $alerts): void
    {
        foreach ($alerts as $alert) {
            $emails = $alert->rule?->emails ?? [];
            $tooOld = $alert->triggered_at->lt(now()->subHours(self::EMAIL_MAX_AGE_HOURS));

            if (!$emails || $tooOld || $this->option('no-mail')) {
                $alert->update(['email_status' => Alert::EMAIL_SKIPPED]);
                continue;
            }

            try {
                Mail::to($emails)->send(new AlertMail($alert->load('vehicle', 'rule')));
                $alert->update(['email_status' => Alert::EMAIL_SENT]);
            } catch (Throwable $e) {
                // Un correo que falla no detiene las demás alertas: queda en la bandeja
                Log::warning('No se pudo mandar la alerta por correo', ['alert_id' => $alert->id, 'error' => $e->getMessage()]);
                $alert->update(['email_status' => Alert::EMAIL_FAILED]);
            }
        }
    }

    protected function reset(Vehicle $vehicle, CarbonImmutable $from): void
    {
        Alert::where('vehicle_id', $vehicle->id)->where('triggered_at', '>=', $from)->delete();
        AlertState::where('vehicle_id', $vehicle->id)->delete();
    }
}
