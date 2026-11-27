<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\RoleAccess;
use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * AC-21: si el admin apaga en «Parámetros» el último perfil con que alguien podía entrar y tiene la sesión abierta, en su
 * siguiente petición se cierra la sesión y vuelve al login con «Tu acceso a Nexus todavía no está habilitado.». A una
 * petición JSON o de Livewire le responde 401 en lugar de redirigir. Si solo se apagó su perfil activo y le queda otro,
 * sigue con ese: Actor ya no resuelve el apagado. Va en el grupo `web` (bootstrap/app.php), después de
 * EnsureUserIsActive.
 */
final class EnsureAccessIsEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof User && ! app(Actor::class)->canSignIn()) {
            Auth::guard(config('fortify.guard'))->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return new JsonResponse(['message' => RoleAccess::DENIED_MESSAGE], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('login')->withErrors(['username' => RoleAccess::DENIED_MESSAGE]);
        }

        return $next($request);
    }
}
