<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * El dueño de una empresa administra a sus propios usuarios. Nunca ve ni toca usuarios
 * de otra empresa: todo parte de su company_id.
 *
 *   GET    /api/company/users
 *   POST   /api/company/users                      {name, email, role}
 *   PATCH  /api/company/users/{user}               {name?, role?}
 *   POST   /api/company/users/{user}/reset-password
 *   DELETE /api/company/users/{user}
 */
class CompanyUserController extends Controller
{
    public function __construct(protected AccountService $accounts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $users = User::where('company_id', $request->user()->company_id)->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn ($u) => $this->accounts->userRow($u))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(User::COMPANY_ROLES)],
        ]);

        [$user, $password] = $this->accounts->createUser(
            $request->user()->company,
            $validated['name'],
            $validated['email'],
            $validated['role'],
        );

        return response()->json(['data' => $this->accounts->userRow($user) + ['temporary_password' => $password]], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeSameCompany($request, $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(User::COMPANY_ROLES)],
        ]);

        // Que no se quite a sí mismo el rol de dueño y deje la empresa sin quien la administre
        if ($user->is($request->user()) && ($validated['role'] ?? User::ROLE_OWNER) !== User::ROLE_OWNER) {
            abort(422, 'No puedes quitarte tu propio rol de dueño.');
        }

        $user->update($validated);

        return response()->json(['data' => $this->accounts->userRow($user)]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeSameCompany($request, $user);

        $password = $this->accounts->resetPassword($user);

        return response()->json(['data' => $this->accounts->userRow($user) + ['temporary_password' => $password]]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeSameCompany($request, $user);
        abort_if($user->is($request->user()), 422, 'No puedes eliminar tu propio usuario.');

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado.']);
    }

    protected function authorizeSameCompany(Request $request, User $user): void
    {
        // 404 y no 403: a otra empresa ni siquiera se le confirma que ese usuario existe
        abort_unless($user->company_id === $request->user()->company_id && !$user->isAdmin(), 404);
    }
}
