<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Usuarios de cualquier empresa desde el panel de EkMira.
 *
 *   POST   /api/admin/companies/{company}/users  {name, email, role} regresa la contraseña temporal una vez
 *   PATCH  /api/admin/users/{user}               {name?, role?}
 *   POST   /api/admin/users/{user}/reset-password
 *   DELETE /api/admin/users/{user}
 */
class UserAdminController extends Controller
{
    public function __construct(protected AccountService $accounts)
    {
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(User::COMPANY_ROLES)],
        ]);

        [$user, $password] = $this->accounts->createUser($company, $validated['name'], $validated['email'], $validated['role']);

        return response()->json(['data' => $this->accounts->userRow($user) + ['temporary_password' => $password]], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_if($user->isAdmin(), 403, 'Los usuarios de EkMira se administran desde la terminal.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(User::COMPANY_ROLES)],
        ]);

        $user->update($validated);

        return response()->json(['data' => $this->accounts->userRow($user)]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        abort_if($user->isAdmin(), 403, 'Los usuarios de EkMira se administran desde la terminal.');

        $password = $this->accounts->resetPassword($user);

        return response()->json(['data' => $this->accounts->userRow($user) + ['temporary_password' => $password]]);
    }

    public function destroy(User $user): JsonResponse
    {
        abort_if($user->isAdmin(), 403, 'Los usuarios de EkMira se administran desde la terminal.');

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado.']);
    }
}
