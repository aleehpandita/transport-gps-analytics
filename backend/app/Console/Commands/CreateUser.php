<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Da de alta un usuario del panel, o lo actualiza si el correo ya existe.
 *
 *   php artisan users:create "Alejandra" tu@correo.com --admin
 *   php artisan users:create "Kevin" kevin@correo.com --vehicles=2
 *
 * La contraseña se pide en pantalla, sin mostrarse, para que no quede en el historial de la terminal.
 */
class CreateUser extends Command
{
    protected $signature = 'users:create
        {name : Nombre que se muestra en el panel}
        {email : Correo con el que inicia sesión}
        {--admin : Ve todas las unidades}
        {--vehicles= : IDs de las unidades que puede ver, separados por coma (solo clientes)}';

    protected $description = 'Da de alta o actualiza un usuario del panel';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $isAdmin = (bool) $this->option('admin');
        $vehicleIds = $this->parseVehicleIds();

        if ($vehicleIds === null) {
            return self::FAILURE;
        }

        if ($isAdmin && $vehicleIds !== []) {
            $this->warn('Un admin ve todas las unidades: --vehicles se ignora.');
            $vehicleIds = [];
        }

        if (!$isAdmin && $vehicleIds === []) {
            $this->warn('Cliente sin unidades asignadas: va a entrar al panel pero no verá ninguna.');
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
                'role' => $isAdmin ? User::ROLE_ADMIN : User::ROLE_CLIENT,
            ]
        );

        $user->vehicles()->sync($vehicleIds);

        $this->info(($user->wasRecentlyCreated ? 'Creado' : 'Actualizado') . ": {$user->name} <{$user->email}>");
        $this->line('  Rol: ' . $user->role);

        if (!$isAdmin) {
            $names = Vehicle::whereIn('id', $vehicleIds)->pluck('name')->implode(', ');
            $this->line('  Unidades: ' . ($names !== '' ? $names : 'ninguna'));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null null si algún ID no existe
     */
    protected function parseVehicleIds(): ?array
    {
        $raw = trim((string) $this->option('vehicles'));

        if ($raw === '') {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
        $found = Vehicle::whereIn('id', $ids)->pluck('id')->all();
        $missing = array_diff($ids, $found);

        if ($missing !== []) {
            $this->error('No existen las unidades: ' . implode(', ', $missing));
            return null;
        }

        return $ids;
    }
}