<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si un usuario se desactiva con la sesión abierta, en su siguiente petición se cierra la sesión
 * y vuelve al login. El aviso no explica el motivo, igual que el login no distingue a un usuario
 * inactivo de una credencial incorrecta. A una petición JSON o de Livewire le responde 401 en
 * lugar de redirigir. Va en el grupo `web` (bootstrap/app.php), antes de EnsurePasswordIsChanged.
 */
final class EnsureUserIsActive
{
    private const string MESSAGE = 'Tu sesión terminó. Vuelve a iniciar sesión.';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::guard(config('fortify.guard'))->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return new JsonResponse(['message' => self::MESSAGE], Response::HTTP_UNAUTHORIZED);
            }

            return redirect()->route('login')->withErrors(['username' => self::MESSAGE]);
        }

        return $next($request);
    }
}
