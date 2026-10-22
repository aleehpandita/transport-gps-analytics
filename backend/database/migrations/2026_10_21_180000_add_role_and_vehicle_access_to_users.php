<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos del panel:
 *   - role: 'admin' ve todas las unidades; 'client' solo las que tenga asignadas.
 *   - user_vehicle: qué unidades ve cada cliente (el dueño de Kevs1 solo ve Kevs1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('client')->after('email');
        });

        Schema::create('user_vehicle', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_vehicle');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};