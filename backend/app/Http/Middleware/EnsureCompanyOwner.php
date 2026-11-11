<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo el dueño de una empresa administra a los usuarios de su empresa.
 */
class EnsureCompanyOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isOwner() && $user->company_id !== null, 403, 'Solo el dueño de la empresa.');

        return $next($request);
    }
}
