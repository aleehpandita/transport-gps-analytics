<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas rápidas sobre las decisiones dentro de esta migración: 
     * imei y traccar_device_id van con unique() — evita que por error se dé de alta el mismo dispositivo dos veces 
     * 
     * plate, make, model son nullable() — al piloto probablemente no le hayas cargado todavía esos datos administrativos, y no queremos que eso bloquee insertar el vehículo con lo que sí tenemos ahora mismo (nombre, IMEI, traccar_device_id).
     * 
     * No hay vehicle_id como columna — el id() autoincremental de Laravel es nuestro vehicle_id interno, el que ya definimos en el contrato como la llave que usan trips y positions.
     * 
     * Run the migrations.
     */
   public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plate')->nullable();
            $table->string('imei')->unique();
            $table->unsignedBigInteger('traccar_device_id')->unique();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
