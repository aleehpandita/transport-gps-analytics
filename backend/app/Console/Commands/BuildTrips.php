<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\TripBuilderService;
use Illuminate\Console\Command;

class BuildTrips extends Command
{
    protected $signature = 'trips:build
        {--vehicle= : ID del vehículo (por default, todos los activos)}
        {--from= : Fecha inicio (Y-m-d H:i:s), por default últimas 48h}
        {--to= : Fecha fin (Y-m-d H:i:s), por default ahora}';

    protected $description = 'Construye trips a partir de las positions ya sincronizadas';

    public function handle(TripBuilderService $builder): int
    {
        $from = $this->option('from') ?? now()->subHours(48)->toDateTimeString();
        $to = $this->option('to') ?? now()->toDateTimeString();

        $vehicles = $this->option('vehicle')
            ? Vehicle::where('id', $this->option('vehicle'))->get()
            : Vehicle::where('active', true)->get();

        if ($vehicles->isEmpty()) {
            $this->warn('No hay vehículos que procesar.');
            return self::SUCCESS;
        }

        foreach ($vehicles as $vehicle) {
            $this->info("Procesando {$vehicle->name}...");

            $trips = $builder->buildForVehicle($vehicle, $from, $to);

            $this->info("  → {$trips->count()} viaje(s) detectado(s).");
        }

        return self::SUCCESS;
    }
}