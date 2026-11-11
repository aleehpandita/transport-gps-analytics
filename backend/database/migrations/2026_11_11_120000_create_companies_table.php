<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas por empresa. Cada cliente de EkMira es una empresa; sus unidades y usuarios
 * cuelgan de ella y un usuario solo ve las unidades de su empresa.
 *
 * Roles:
 *   - admin: equipo de EkMira. Ve todas las empresas y las administra.
 *   - owner: dueño de la empresa. Ve todo lo suyo y da de alta a sus usuarios.
 *   - operator: opera el día a día (confirma servicios, marca viajes).
 *   - viewer: solo consulta.
 *
 * Los clientes que ya existían ('client' con unidades asignadas en user_vehicle) pasan a
 * ser dueños de una empresa con esas mismas unidades, así nadie pierde acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();       // razón social
            $table->string('rfc', 13)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('plan', 50)->nullable();
            $table->decimal('monthly_price', 10, 2)->nullable(); // por unidad, sin IVA
            $table->string('status', 20)->default('active');   // active | suspended
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role', 20)->default('viewer')->change();
        });

        $this->moveClientsToCompanies();
    }

    public function down(): void
    {
        // Regresa a los dueños como 'client' con sus unidades en user_vehicle
        $now = now();
        $owners = DB::table('users')->whereNotNull('company_id')->whereIn('role', ['owner', 'operator', 'viewer'])->get();
        foreach ($owners as $user) {
            $vehicleIds = DB::table('vehicles')->where('company_id', $user->company_id)->pluck('id');
            foreach ($vehicleIds as $vehicleId) {
                DB::table('user_vehicle')->insertOrIgnore([
                    'user_id' => $user->id, 'vehicle_id' => $vehicleId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        DB::table('users')->whereIn('role', ['owner', 'operator', 'viewer'])->update(['role' => 'client']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->string('role', 20)->default('client')->change();
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
        Schema::dropIfExists('companies');
    }

    protected function moveClientsToCompanies(): void
    {
        $now = now();
        $clients = DB::table('users')->where('role', 'client')->orderBy('id')->get();

        foreach ($clients as $client) {
            $vehicleIds = DB::table('user_vehicle')->where('user_id', $client->id)->pluck('vehicle_id');

            // Si sus unidades ya quedaron en una empresa (otro cliente con las mismas), se une a ella
            $companyId = $vehicleIds->isEmpty()
                ? null
                : DB::table('vehicles')->whereIn('id', $vehicleIds)->whereNotNull('company_id')->value('company_id');

            if ($companyId === null) {
                $companyId = DB::table('companies')->insertGetId([
                    'name' => $client->name,
                    'contact_name' => $client->name,
                    'contact_email' => $client->email,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('vehicles')->whereIn('id', $vehicleIds)->whereNull('company_id')->update(['company_id' => $companyId]);
            DB::table('users')->where('id', $client->id)->update(['company_id' => $companyId, 'role' => 'owner']);
        }
    }
};
