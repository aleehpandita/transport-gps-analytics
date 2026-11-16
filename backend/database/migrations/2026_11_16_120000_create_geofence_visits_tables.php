<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entradas y salidas de las geocercas que dibuja el cliente (reporte de PDI).
 *
 * geofence_visits: una fila por cada vez que una unidad estuvo dentro de una geocerca:
 *   cuándo entró, cuándo salió y cuánto tiempo estuvo. Se registran todas, haya o no una
 *   alerta configurada para esa geocerca.
 *   - exited_at null: sigue dentro.
 *   - started_inside: ya estaba dentro cuando se empezó a calcular, así que la hora de
 *     entrada es aproximada (el inicio del cálculo o la primera posición).
 *
 * geofence_visit_states: memoria del cálculo por unidad (hasta qué posición leyó y de qué
 *   lado está de cada geocerca), igual que alert_states con las alertas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofence_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('geofence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->dateTime('entered_at');
            $table->dateTime('exited_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('started_inside')->default(false);
            $table->timestamps();

            // Recalcular no duplica visitas
            $table->unique(['vehicle_id', 'geofence_id', 'entered_at']);
            $table->index(['company_id', 'entered_at']);
        });

        Schema::create('geofence_visit_states', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained()->cascadeOnDelete();
            $table->dateTime('last_device_time')->nullable();
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofence_visit_states');
        Schema::dropIfExists('geofence_visits');
    }
};
