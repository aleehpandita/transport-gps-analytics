<?php

// Referencia para cuando se inicialice el proyecto Laravel (Fase 1).
// Mover a backend/database/seeders/ZonesSeeder.php una vez exista el proyecto.
// Lee el catálogo desde docs/zones.csv para no duplicar los datos en dos lugares.

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ZonesSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = base_path('../docs/zones.csv');
        $rows = array_map('str_getcsv', file($csvPath));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            $zone = array_combine($header, $row);

            DB::table('zones')->updateOrInsert(
                ['id' => (int) $zone['id']],
                [
                    'name' => $zone['name'],
                    'time_from_airport_raw' => $zone['time_from_airport_raw'],
                    'time_from_airport_minutes' => (int) $zone['time_from_airport_minutes'],
                    'is_airport' => filter_var($zone['is_airport'], FILTER_VALIDATE_BOOLEAN),
                    'requires_ferry_transfer' => filter_var($zone['requires_ferry_transfer'], FILTER_VALIDATE_BOOLEAN),
                    'is_active' => filter_var($zone['is_active'], FILTER_VALIDATE_BOOLEAN),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
