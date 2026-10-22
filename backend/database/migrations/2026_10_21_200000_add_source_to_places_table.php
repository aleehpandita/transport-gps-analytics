<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde viene cada lugar:
 *   - manual:  dado de alta con places:add (lobbies y banquetas comprobados con datos reales)
 *   - osm:     importado de OpenStreetMap; entra inactivo, como candidato con nombre
 *   - learned: encontrado por DBSCAN a partir de las paradas repetidas
 *
 * El nombre deja de ser único: OpenStreetMap tiene hoteles con el mismo nombre (cadenas,
 * edificios del mismo complejo). Lo único para OSM es su id (por ejemplo "way/123456").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->index('name');

            $table->string('source', 20)->default('manual')->after('type');
            $table->string('external_id', 50)->nullable()->after('source');

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->dropColumn(['source', 'external_id']);

            $table->dropIndex(['name']);
            $table->unique('name');
        });
    }
};