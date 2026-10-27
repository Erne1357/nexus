<?php

use App\Modules\Core\Http\Middleware\EnsurePasswordIsChanged;
use App\Modules\Core\Http\Middleware\EnsureUserIsActive;
use App\Modules\Core\Http\Middleware\NormalizeSidebarState;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // La app solo es accesible a través de nginx dentro de la red de Docker.
        $middleware->trustProxies(at: '*');

        // Para toda ruta web (Core): cierra las demás sesiones cuando cambia la contraseña, la de
        // un usuario desactivado, obliga a cambiar la contraseña en el primer acceso y deja el
        // estado de la barra lateral de MaryUI en 'true' o 'false'.
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            EnsurePasswordIsChanged::class,
            NormalizeSidebarState::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
