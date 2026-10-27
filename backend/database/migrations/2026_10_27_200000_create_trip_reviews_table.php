<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que una persona confirmó de un viaje: si llevó pasajeros, si fue un no-show o uso
 * personal, con una nota. Manda sobre lo que el GPS supone.
 *
 * Se liga por unidad y hora de inicio, no por el id del viaje: el Trip Builder vuelve a
 * armar los viajes y los ids cambian, pero el inicio de un viaje no.
 *
 * Además son las etiquetas reales para entrenar el clasificador de paradas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->dateTime('trip_started_at');
            // service, empty, no_show, personal
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['vehicle_id', 'trip_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_reviews');
    }
};