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
            $table->enum('geometry_type', ['Polygon', 'MultiPolygon', 'Point', 'DestinationArea'])->nullable();
            $table->json('geometry')->nullable();
            $table->string('north_boundary')->nullable();
            $table->string('south_boundary')->nullable();
            $table->json('reference_hotels')->nullable();
            $table->text('notes')->nullable();
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
