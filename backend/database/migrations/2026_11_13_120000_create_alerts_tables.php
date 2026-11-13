<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas configurables por cada empresa.
 *
 * geofences: zonas que dibuja el cliente (un círculo o un polígono). Son de su empresa y
 *   solo las usan sus reglas.
 *
 * alert_rules: qué avisar. Tipo (velocidad, sale o entra de una geocerca, parada larga,
 *   sin señal, movimiento fuera de horario), a qué unidades, qué días y horario, cada
 *   cuánto puede repetirse y a qué correos llega.
 *
 * alerts: la bandeja. Cada aviso que salió, con su unidad, hora, lugar y si ya se leyó.
 *
 * alert_states: memoria del motor por unidad (hasta qué posición leyó y lo que está en
 *   curso para cada regla), igual que vehicle_states con el detector de eventos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('shape', 10); // circle | polygon
            // Centro del círculo; en un polígono, el centro de sus puntos (para el mapa)
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->nullable();
            // Polígono: [[lat, lng], ...]
            $table->json('points')->nullable();
            $table->string('color', 7)->default('#5b8def');
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // overspeed, geofence_exit, geofence_enter, long_stop, signal_lost, after_hours
            $table->string('type', 20);
            // Lo propio de cada tipo: speed_kmh, seconds, minutes, geofence_id, except_in_geofences
            $table->json('settings')->nullable();
            // null = todas las unidades de la empresa
            $table->json('vehicle_ids')->nullable();
            // Días de la semana en que aplica (1 = lunes ... 7 = domingo)
            $table->json('days');
            // Horario en hora de Cancún, HH:MM. Sin horario = todo el día.
            // Si "desde" es mayor que "hasta" cruza la medianoche (22:00 a 05:00).
            $table->string('time_from', 5)->nullable();
            $table->string('time_to', 5)->nullable();
            // Minutos mínimos entre dos avisos de esta regla para la misma unidad (0 = cada vez)
            $table->unsignedInteger('repeat_minutes')->default(0);
            $table->json('emails')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            // Cuándo pasó (hora del GPS), no cuándo se procesó
            $table->dateTime('triggered_at');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // Velocidad máxima en un exceso, minutos en una parada o sin señal
            $table->decimal('value', 8, 2)->nullable();
            $table->string('message', 255);
            $table->json('data')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->foreignId('read_by')->nullable()->constrained('users')->nullOnDelete();
            // sent | failed | skipped (sin correos o aviso viejo)
            $table->string('email_status', 10)->nullable();
            $table->timestamps();

            // La misma regla no avisa dos veces de lo mismo si se reprocesa
            $table->unique(['alert_rule_id', 'vehicle_id', 'triggered_at']);
            $table->index(['company_id', 'triggered_at']);
            $table->index(['company_id', 'read_at']);
        });

        Schema::create('alert_states', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained()->cascadeOnDelete();
            $table->dateTime('last_device_time')->nullable();
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_states');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('geofences');
    }
};
