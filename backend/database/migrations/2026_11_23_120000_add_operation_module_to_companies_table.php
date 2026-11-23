<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Operación por empresa (servicios detectados, no-shows, predicciones de llegada).
 *
 * La BASE (mapa, historial, reportes, eventos, alertas) la tienen todas las empresas. La
 * Operación es un módulo aparte: si la empresa no lo contrató, el panel no muestra la
 * pestaña Operación ni el panel de Predicciones de llegada.
 *
 * Todas empiezan sin el módulo. Solo lo conservan las empresas que ya lo usan, es decir,
 * las que tienen en alguna de sus unidades órdenes de servicio importadas, viajes marcados
 * o servicios programados (por ejemplo, Kevs Trips). Una empresa que solo contrató la BASE
 * (por ejemplo, Brandon) se queda sin él. Después se cambia en Clientes → Editar.
 */
return new class extends Migration
{
    // Tablas de Operación: si una unidad de la empresa tiene datos aquí, la empresa la usa
    protected const OPERATION_TABLES = ['service_orders', 'trip_reviews', 'scheduled_services'];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('operation_module')->default(false)->after('monthly_price');
        });

        foreach (self::OPERATION_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $companyIds = DB::table('vehicles')
                ->whereNotNull('company_id')
                ->whereIn('id', DB::table($table)->select('vehicle_id'))
                ->distinct()
                ->pluck('company_id');

            DB::table('companies')->whereIn('id', $companyIds)->update(['operation_module' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('operation_module');
        });
    }
};
