<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

class ZonesSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = base_path('../docs/zones.csv');
        $rows = array_map('str_getcsv', file($csvPath));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            $zone = array_combine($header, $row);

            Zone::updateOrCreate(
                ['id' => (int) $zone['id']],
                [
                    'name' => $zone['name'],
                    'time_from_airport_raw' => $zone['time_from_airport_raw'],
                    'time_from_airport_minutes' => (int) $zone['time_from_airport_minutes'],
                    'is_airport' => filter_var($zone['is_airport'], FILTER_VALIDATE_BOOLEAN),
                    'requires_ferry_transfer' => filter_var($zone['requires_ferry_transfer'], FILTER_VALIDATE_BOOLEAN),
                    'is_active' => filter_var($zone['is_active'], FILTER_VALIDATE_BOOLEAN),
                ]
            );
        }
    }
}