<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de servicio: lo que la empresa dice que se iba a hacer (la hoja del chofer).
 * Hoy se cargan desde un CSV con orders:import; después las llenará la IA leyendo la foto
 * de la hoja. Contra ellas se mide la precisión de lo que detecta el GPS
 * (operations:evaluate) y más adelante se avisa de retrasos y no-shows.
 *
 * Sin nombres de pasajeros: solo hora, tipo, lugar, terminal y número de personas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->date('service_date');
            // Hora de la orden, hora de Cancún. Llegada: cita en la terminal. Salida: cita en el hotel.
            $table->string('scheduled_time', 5);
            // llegada, salida o traslado
            $table->string('type', 20);
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            // El lugar como viene escrito en la orden, aunque no esté en el catálogo
            $table->string('place_text');
            // T1, T2, T3, T4 o null
            $table->string('terminal', 10)->nullable();
            $table->unsignedSmallInteger('pax')->nullable();
            // csv, manual o ai
            $table->string('source', 20)->default('csv');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};