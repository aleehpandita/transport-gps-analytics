<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Altas de usuarios y resúmenes de unidades para administrar empresas. Lo usan el panel
 * de EkMira (todas las empresas) y el dueño de cada empresa (solo la suya).
 */
class AccountService
{
    // Estacionado, el FMC920 se duerme y no reporta en toda la noche: hasta un día sin
    // reportar es normal. Más que eso ya es un equipo que hay que revisar.
    public const NO_SIGNAL_HOURS = 24;

    /**
     * Da de alta un usuario con una contraseña temporal. La contraseña se regresa en
     * claro una sola vez para entregársela; en la base queda cifrada.
     *
     * @return array{0: User, 1: string}
     */
    public function createUser(?Company $company, string $name, string $email, string $role): array
    {
        $password = $this->temporaryPassword();

        $user = User::create([
            'company_id' => $company?->id,
            'name' => $name,
            'email' => strtolower(trim($email)),
            'password' => $password,
            'role' => $role,
        ]);

        return [$user, $password];
    }

    /**
     * Nueva contraseña temporal para quien olvidó la suya.
     */
    public function resetPassword(User $user): string
    {
        $password = $this->temporaryPassword();
        $user->update(['password' => $password]);

        return $password;
    }

    public function userRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company_id' => $user->company_id,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * Una unidad con su último reporte, para ver de un vistazo qué equipos no están mandando.
     */
    public function vehicleRow(Vehicle $vehicle): array
    {
        $last = $vehicle->latestPosition?->device_time;
        $hours = $last ? Carbon::parse($last)->diffInMinutes(now()) / 60 : null;

        return [
            'id' => $vehicle->id,
            'company_id' => $vehicle->company_id,
            'name' => $vehicle->name,
            'plate' => $vehicle->plate,
            'imei' => $vehicle->imei,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'active' => (bool) $vehicle->active,
            'traccar_device_id' => $vehicle->traccar_device_id,
            'last_report_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
            // never: nunca ha reportado; ok: reportó en el último día; no_signal: más de un día
            'signal' => $last === null ? 'never' : ($hours > self::NO_SIGNAL_HOURS ? 'no_signal' : 'ok'),
        ];
    }

    /**
     * 12 caracteres sin símbolos para que se pueda dictar por teléfono o WhatsApp.
     */
    protected function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }
}
