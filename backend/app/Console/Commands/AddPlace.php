<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\ZoneMatchingService;
use Illuminate\Console\Command;

/**
 * Da de alta o actualiza un lugar de servicio.
 *
 *   php artisan places:add "Aeropuerto T3 salidas" "21.03817, -86.86962" --type=airport_terminal
 *
 * Las coordenadas van juntas y entre comillas, tal cual se copian de Google Maps. Separadas,
 * Laravel confundiría la longitud negativa (-86...) con una opción.
 *
 * Si el nombre ya existe, actualiza sus datos. La zona se calcula sola con las coordenadas.
 */
class AddPlace extends Command
{
    protected $signature = 'places:add
        {name : Nombre del lugar, por ejemplo "Valentin Imperial Maya lobby"}
        {coordinates : "latitud, longitud" entre comillas, como se copian de Google Maps}
        {--type=hotel : airport_terminal, hotel u other}
        {--radius=100 : Radio en metros}
        {--notes= : Nota opcional}';

    protected $description = 'Da de alta o actualiza un lugar de servicio (banqueta, lobby)';

    public function handle(ZoneMatchingService $zones): int
    {
        $type = $this->option('type');
        $radius = (int) $this->option('radius');

        $parts = array_map('trim', explode(',', $this->argument('coordinates')));

        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            $this->error('Coordenadas inválidas. Usa "latitud, longitud", por ejemplo "21.03817, -86.86962".');
            return self::FAILURE;
        }

        [$latitude, $longitude] = array_map('floatval', $parts);

        if (!in_array($type, Place::TYPES, true)) {
            $this->error('Tipo inválido. Usa uno de: ' . implode(', ', Place::TYPES));
            return self::FAILURE;
        }

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $this->error('Coordenadas fuera de rango. El orden es latitud y luego longitud.');
            return self::FAILURE;
        }

        if ($radius < 20 || $radius > 1000) {
            $this->error('El radio debe estar entre 20 y 1000 metros.');
            return self::FAILURE;
        }

        $zone = $zones->resolve($latitude, $longitude);

        $place = Place::updateOrCreate(
            ['name' => $this->argument('name')],
            [
                'type' => $type,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_m' => $radius,
                'zone_id' => $zone?->id,
                'is_active' => true,
                'notes' => $this->option('notes'),
            ]
        );

        $this->info(($place->wasRecentlyCreated ? 'Creado' : 'Actualizado') . ": {$place->name}");
        $this->line("  {$place->type}, radio {$place->radius_m} m, zona " . ($zone?->name ?? 'sin zona'));

        return self::SUCCESS;
    }
}