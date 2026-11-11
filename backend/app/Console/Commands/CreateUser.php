<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Da de alta un usuario del panel, o lo actualiza si el correo ya existe.
 *
 *   php artisan users:create "Alejandra" tu@correo.com --admin
 *   php artisan users:create "Kevin" kevin@correo.com --company=1 --role=owner
 *
 * Lo normal es dar de alta usuarios desde el panel (Clientes); esto sirve para el primer
 * admin y para emergencias.
 *
 * La contraseña se pide en pantalla, sin mostrarse, para que no quede en el historial de la terminal.
 */
class CreateUser extends Command
{
    protected $signature = 'users:create
        {name : Nombre que se muestra en el panel}
        {email : Correo con el que inicia sesión}
        {--admin : Equipo de EkMira, ve todas las empresas}
        {--company= : ID de la empresa (usuarios de cliente)}
        {--role=owner : owner, operator o viewer (usuarios de cliente)}';

    protected $description = 'Da de alta o actualiza un usuario del panel';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $isAdmin = (bool) $this->option('admin');
        $role = $isAdmin ? User::ROLE_ADMIN : (string) $this->option('role');
        $company = null;

        if (!$isAdmin) {
            if (!in_array($role, User::COMPANY_ROLES, true)) {
                $this->error('Rol no válido. Usa: ' . implode(', ', User::COMPANY_ROLES));
                return self::FAILURE;
            }

            $company = Company::find($this->option('company'));
            if ($company === null) {
                $this->error('Falta --company con el ID de una empresa que exista.');
                return self::FAILURE;
            }
        }

        $password = $this->secret('Contraseña (mínimo 10 caracteres)');
        $confirmation = $this->secret('Repite la contraseña');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:10', 'confirmed']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $this->argument('name'),
                'password' => $password, // el cast 'hashed' del modelo la cifra
                'role' => $role,
                'company_id' => $company?->id,
            ]
        );

        $this->info(($user->wasRecentlyCreated ? 'Creado' : 'Actualizado') . ": {$user->name} <{$user->email}>");
        $this->line('  Rol: ' . $user->role);
        if ($company) {
            $this->line("  Empresa: {$company->name} ({$company->vehicles()->count()} unidades)");
        }

        return self::SUCCESS;
    }
}
