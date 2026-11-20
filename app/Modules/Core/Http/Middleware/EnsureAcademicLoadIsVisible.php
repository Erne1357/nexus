<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * MN-05: mientras el admin tiene oculta la carga académica, sus rutas (la lista explícita AcademicLoadVisibility::ROUTES)
 * no abren para quien no tiene `core.academic_load.view_hidden`. Las que piden sesión responden 403; las públicas (la
 * consulta de horarios publicados y sus PDF), 404, como si no existieran, también con sesión. A un invitado en una que pide
 * sesión no le responde: de él se encarga `auth`, que lo manda al login.
 *
 * Va en el grupo `web` (bootstrap/app.php), como EnsurePasswordIsChanged, para que decida la lista y no el grupo de rutas de
 * cada módulo; y es middleware persistente de Livewire (CoreServiceProvider), así que una acción de un componente pintado
 * en una página de la carga también se niega. Cualquier otra ruta pasa sin leer nada.
 */
final class EnsureAcademicLoadIsVisible
{
    public function __construct(
        private readonly AcademicLoadVisibility $visibility,
        private readonly Actor $context,
        private readonly Router $router,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null || ! AcademicLoadVisibility::isLoadRoute($route->getName()) || ! $this->visibility->hiddenFrom($this->context)) {
            return $next($request);
        }

        if (! $this->needsLogin($route)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($this->context->user() === null) {
            return $next($request);
        }

        abort(Response::HTTP_FORBIDDEN, 'La carga académica no está disponible por ahora.');
    }

    /**
     * Si la ruta pide sesión: alguno de sus middleware, ya resueltos (`auth`, `auth:<guardia>`), es Authenticate o lo extiende.
     */
    private function needsLogin(Route $route): bool
    {
        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && is_a(Str::before($middleware, ':'), Authenticate::class, true)) {
                return true;
            }
        }

        return false;
    }
}
