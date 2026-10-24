<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos de las unidades y el estado que necesita el detector para no repetirlos.
 *
 * vehicle_events: lo que pasó (entró a una zona, llegó a un lobby, exceso de velocidad...).
 *   Los eventos que duran (exceso, parada larga, sin señal, estancia en un lugar) se crean
 *   abiertos (ended_at null) y se cierran cuando terminan.
 *
 * vehicle_states: una fila por unidad con hasta qué posición se procesó y lo que está en
 *   curso. Gracias a esto el detector solo lee posiciones nuevas, sin importar cuántas
 *   unidades o meses haya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            // zone_enter, zone_exit, place_visit, overspeed, long_stop, signal_lost
            $table->string('type', 30);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained('places')->nullOnDelete();
            // Velocidad máxima en un exceso; en los demás, sin uso por ahora
            $table->decimal('value', 8, 2)->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            // El mismo evento no se registra dos veces
            $table->unique(['vehicle_id', 'type', 'started_at']);
            $table->index('started_at');
            $table->index(['vehicle_id', 'started_at']);
        });

        Schema::create('vehicle_states', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained()->cascadeOnDelete();
            // Última posición procesada
            $table->dateTime('last_device_time')->nullable();
            // Lo que está en curso: zona, lugar, exceso, parada... (ver EventDetector)
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_states');
        Schema::dropIfExists('vehicle_events');
    }
};