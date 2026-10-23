<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Semáforos de OpenStreetMap. El Trip Builder no cuenta como parada un alto junto a un
 * semáforo, y DBSCAN no los aprende como lugares: el 21 y 22 oct, una fila en un cruce de
 * Cancún partía los viajes de Kevs1 todos los días a la misma hora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_signals', function (Blueprint $table) {
            $table->id();
            $table->string('osm_id', 30)->unique(); // "node/123456"
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_signals');
    }
};