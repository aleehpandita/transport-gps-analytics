<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lugares de servicio: donde se sube o se baja gente (banquetas del aeropuerto, lobbies
 * de hotel). Son mucho más chicos que las zonas: una zona dice "Puerto Morelos", un lugar
 * dice "lobby de Haven". El Trip Builder cierra un viaje cuando la unidad se detiene en uno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // airport_terminal, hotel u other
            $table->string('type', 30)->default('hotel');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // Chico a propósito: las terminales T2, T3 y T4 están a unos cientos de metros
            $table->unsignedSmallInteger('radius_m')->default(100);
            $table->foreignId('zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};