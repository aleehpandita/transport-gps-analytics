<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 
     * Nota: origin_zone_id/destination_zone_id van nullable a propósito — el TripBuilderService en su primera versión va a segmentar viajes a partir de positions, pero no va a resolver todavía a qué zona corresponde el origen/destino (eso requiere lógica de geocerca/matching contra zones que es un paso aparte, lo dejamos para después). Por ahora, cada viaje se crea con las zonas en null y las llenamos en una segunda pasada.
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->foreignId('destination_zone_id')->nullable()->constrained('zones')->nullOnDelete();

            $table->dateTime('started_at');
            $table->dateTime('ended_at');
            $table->unsignedInteger('duration_seconds');
            $table->double('distance_km')->nullable();

            $table->double('average_speed')->nullable();
            $table->double('max_speed')->nullable();
            $table->unsignedInteger('stops_count')->default(0);
            $table->unsignedInteger('stopped_seconds')->default(0);

            $table->enum('data_source', ['real', 'synthetic'])->default('real');

            $table->timestamps();

            $table->index(['vehicle_id', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
