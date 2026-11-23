<?php

use App\Modules\Core\Http\Middleware\EnsureAcademicLoadIsVisible;
use App\Modules\Core\Http\Middleware\EnsureAccessIsEnabled;
use App\Modules\Core\Http\Middleware\EnsurePasswordIsChanged;
use App\Modules\Core\Http\Middleware\EnsureUserIsActive;
use App\Modules\Core\Http\Middleware\NormalizeSidebarState;
use App\Modules\Core\Http\Middleware\SetSecurityHeaders;
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

        // Cabeceras de seguridad en toda respuesta, también sin sesión y en errores (tarea 4.8).
        $middleware->append(SetSecurityHeaders::class);

        // Para toda ruta web (Core): cierra las demás sesiones cuando cambia la contraseña, la de
        // un usuario desactivado y la de quien se quedó sin perfil habilitado (AC-21), obliga a cambiar la contraseña en el primer acceso, deja el
        // estado de la barra lateral de MaryUI en 'true' o 'false' y cierra las rutas de la carga
        // académica mientras el admin la tiene oculta (MN-05).
        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            EnsureAccessIsEnabled::class,
            EnsurePasswordIsChanged::class,
            NormalizeSidebarState::class,
            EnsureAcademicLoadIsVisible::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
