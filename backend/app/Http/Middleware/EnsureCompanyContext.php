<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que es de una empresa (geocercas, reglas de alerta, bandeja) necesita saber de cuál.
 * Un usuario de empresa trabaja con la suya. Un admin de EkMira primero elige una con
 * "Ver como", para no crear por error algo en la empresa equivocada.
 *
 * Deja el id en $request->attributes->get('company_id').
 */
class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyId = $user?->visibleCompanyId();

        if ($companyId === null) {
            abort($user?->isAdmin() ? 400 : 403, $user?->isAdmin()
                ? 'Elige un cliente con "Ver como" para ver o configurar sus alertas.'
                : 'Tu usuario no pertenece a ninguna empresa.');
        }

        $request->attributes->set('company_id', $companyId);

        return $next($request);
    }
}
