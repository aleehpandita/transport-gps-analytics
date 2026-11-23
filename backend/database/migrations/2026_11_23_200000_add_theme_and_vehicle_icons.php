<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apariencia del panel:
 *  - users.theme: claro u oscuro, por usuario, para que se vea igual en el celular y en la
 *    computadora. Los usuarios nuevos empiezan en claro.
 *  - vehicles.icon y vehicles.color: cómo se dibuja cada unidad en el mapa. Los elige el
 *    cliente. color null = el verde de siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('light')->after('role');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('icon', 10)->default('van')->after('model');
            $table->string('color', 7)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['icon', 'color']);
        });
    }
};
