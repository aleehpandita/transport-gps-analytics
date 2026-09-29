<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->string('region')->default('Cancun-Riviera Maya');
            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();
            // Radio en km, solo para zonas tipo "área" (ej. Cancún, que es zona amplia,
            // no un punto de frontera en carretera como el resto).
            $table->double('geofence_radius_km')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            //
        });
    }
};
