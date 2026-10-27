<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras el usuario tenga `must_change_password`, solo puede ver la pantalla de cambio de
 * contraseña, enviarla o cerrar sesión (supuesto 2 de PROJECT.md); a una petición JSON o de
 * Livewire le responde 403 en lugar de redirigir. Va en el grupo `web`
 * (bootstrap/app.php) para que ninguna ruta de ningún módulo se quede sin él; a los invitados
 * no les hace nada (de ellos se encarga `auth`).
 */
final class EnsurePasswordIsChanged
{
    /**
     * Rutas permitidas antes de cambiar la contraseña.
     *
     * @var list<string>
     */
    private const array ALLOWED_ROUTES = ['user-password.edit', 'user-password.update', 'logout'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_ROUTES)) {
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return new JsonResponse(['message' => 'Cambia tu contraseña para continuar.'], Response::HTTP_FORBIDDEN);
            }

            return redirect()->guest(route('user-password.edit'));
        }

        return $next($request);
    }
}
