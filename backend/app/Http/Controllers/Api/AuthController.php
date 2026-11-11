<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login del panel con sesión (Sanctum en modo SPA). Antes del login, el panel pide
 * GET /sanctum/csrf-cookie para recibir la cookie XSRF-TOKEN.
 */
class AuthController extends Controller
{
    // 5 intentos fallidos por correo e IP, luego hay que esperar un minuto
    protected const MAX_ATTEMPTS = 5;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:' . Str::lower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Vuelve a intentar en {$seconds} segundos.",
            ]);
        }

        if (!Auth::guard('web')->attempt($credentials, remember: true)) {
            RateLimiter::hit($key);

            // Mismo mensaje si el correo no existe o la contraseña está mal: no se revela cuál fue
            throw ValidationException::withMessages([
                'email' => 'Correo o contraseña incorrectos.',
            ]);
        }

        RateLimiter::clear($key);

        // Empresa suspendida (por ejemplo, por falta de pago): no entra hasta que se reactive
        if ($request->user()->company && !$request->user()->company->isActive()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => 'La cuenta de tu empresa está suspendida. Escríbenos a contacto@ekmira.com.',
            ]);
        }

        // Sesión nueva al iniciar: evita que alguien reuse un id de sesión anterior
        $request->session()->regenerate();

        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    protected function userPayload(User $user): array
    {
        $acting = $user->actingCompanyId() ? Company::find($user->actingCompanyId()) : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'can_edit' => $user->canEdit(),
            'company' => $user->company ? ['id' => $user->company->id, 'name' => $user->company->name] : null,
            // Solo admins: la empresa que está viendo como cliente
            'acting_company' => $acting ? ['id' => $acting->id, 'name' => $acting->name] : null,
        ];
    }
}