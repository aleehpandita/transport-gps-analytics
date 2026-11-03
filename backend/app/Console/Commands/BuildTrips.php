<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\TripBuilderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Construye trips a partir de las positions ya sincronizadas.
 *
 *   php artisan trips:build                                   (últimas 48 h)
 *   php artisan trips:build --from="2026-10-19 00:00:00"      (desde esa fecha, día por día)
 *
 * Procesa por bloques de --chunk-hours (24 por defecto) para que la memoria no dependa de
 * cuántos días se pidan: el 26 oct, 8 días juntos (33 mil posiciones) pasaron de 128 MB.
 *
 * Un viaje que cruza el corte entre dos bloques no se parte: el bloque siguiente arranca en
 * el inicio del último viaje del anterior, así ese viaje se vuelve a armar completo.
 */
class BuildTrips extends Command
{
    protected $signature = 'trips:build
        {--vehicle= : ID del vehículo (por default, todos los activos)}
        {--from= : Fecha inicio (Y-m-d H:i:s), por default últimas 48h}
        {--to= : Fecha fin (Y-m-d H:i:s), por default ahora}
        {--chunk-hours=24 : Horas por bloque}';

    protected $description = 'Construye trips a partir de las positions ya sincronizadas';

    public function handle(TripBuilderService $builder): int
    {
        $from = CarbonImmutable::parse($this->option('from') ?? now()->subHours(48)->toDateTimeString());
        $to = CarbonImmutable::parse($this->option('to') ?? now()->toDateTimeString());
        $chunkHours = max(1, (int) $this->option('chunk-hours'));

        $vehicles = $this->option('vehicle')
            ? Vehicle::where('id', $this->option('vehicle'))->get()
            : Vehicle::where('active', true)->get();

        if ($vehicles->isEmpty()) {
            $this->warn('No hay vehículos que procesar.');
            return self::SUCCESS;
        }

        foreach ($vehicles as $vehicle) {
            $this->info("Procesando {$vehicle->name}...");

            $starts = [];
            $cursor = $from;

            while ($cursor < $to) {
                $end = $cursor->addHours($chunkHours)->min($to);
                $trips = $builder->buildForVehicle($vehicle, $cursor->toDateTimeString(), $end->toDateTimeString());

                foreach ($trips as $trip) {
                    $starts[$trip->started_at->getTimestamp()] = true;
                }

                // El siguiente bloque empieza donde empezó el último viaje (puede seguir abierto)
                $lastStart = $trips->last()?->started_at;
                $next = $lastStart ? CarbonImmutable::instance($lastStart) : $end;
                $cursor = $next > $cursor && $next < $end ? $next : $end;
            }

            $this->info('  → ' . count($starts) . ' viaje(s) detectado(s).');
        }

        return self::SUCCESS;
    }
}