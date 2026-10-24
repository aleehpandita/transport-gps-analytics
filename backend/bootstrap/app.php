<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum con sesión: las peticiones que vienen del panel (dominios de
        // SANCTUM_STATEFUL_DOMAINS) se autentican con la cookie de sesión de Laravel.
        // El panel y la API van en el mismo dominio, así que no hacen falta tokens.
        $middleware->statefulApi();

        // A quien no tiene sesión no se le redirige a ningún lado: el login vive en el panel
        // (React), no en Laravel, así que no existe una ruta "login" a la cual mandarlo.
        // En /api responde 401 y el panel muestra su pantalla de login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Todo lo que pase por /api responde en JSON, aunque se abra directo en el navegador
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*'));
    })->create();