<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina posiciones duplicadas por reenvíos del FMC920 y evita nuevas a nivel de base.
 *
 * El dispositivo reenvía registros cuando no recibe confirmación del servidor, y Traccar
 * guarda cada reenvío con un id distinto (docs/piloto-fmc920.md, sección 4.2).
 * Se conserva la primera copia de cada posición, la de id más bajo.
 *
 * El borrado no es reversible: down() solo quita el índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            DELETE FROM positions
            WHERE id NOT IN (
                SELECT keep_id FROM (
                    SELECT MIN(id) AS keep_id
                    FROM positions
                    GROUP BY vehicle_id, device_time, latitude, longitude
                ) AS keepers
            )
        ');

        Schema::table('positions', function (Blueprint $table) {
            $table->unique(
                ['vehicle_id', 'device_time', 'latitude', 'longitude'],
                'positions_vehicle_time_coords_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique('positions_vehicle_time_coords_unique');
        });
    }
};