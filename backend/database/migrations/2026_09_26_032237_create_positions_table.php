<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Puntos a notar sobre esta migración, conforme a lo que ya decidimos: 
     * 
     * foreignId('vehicle_id')->constrained() — crea la FK automáticamente contra vehicles.id, con cascadeOnDelete() (si algún día borras un vehículo de prueba, sus posiciones se van con él; en producción probablemente cambiaríamos esto por restrictOnDelete(), pero para desarrollo está bien así).
     * 
     * traccar_position_id con unique() y nullable() — así se cumple la idempotencia que planteamos desde el inicio del proyecto (nunca duplicar una posición ya sincronizada), y queda nulo libremente para los registros sintéticos que generen Kimberly/Mario.
     * 
     * El índice compuesto (vehicle_id, device_time) — es el que va a hacer rápidas las consultas del TripBuilderService más adelante (traer posiciones de un vehículo ordenadas por tiempo).
     * 
     * data_source enum('real', 'synthetic') — para diferenciar las posiciones reales de Traccar de las generadas por el TripBuilderService.
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('traccar_position_id')->nullable()->unique();

            $table->double('latitude');
            $table->double('longitude');
            $table->double('altitude')->nullable();
            $table->double('speed')->nullable(); // km/h, convertido desde knots al ingerir
            $table->double('course')->nullable();
            $table->double('accuracy')->nullable();

            $table->boolean('ignition')->nullable();
            $table->boolean('motion')->nullable();
            $table->boolean('valid')->default(true);

            $table->dateTime('device_time'); // timestamp canónico
            $table->dateTime('server_time')->nullable();

            $table->json('attributes')->nullable(); // payload crudo completo de Traccar

            $table->enum('data_source', ['real', 'synthetic'])->default('real');

            $table->timestamps();

            $table->index(['vehicle_id', 'device_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
