<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\AccessRequirements;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AC-22 (supuesto por confirmar): mientras un alumno no haya aceptado la versión vigente del aviso de privacidad
 * (AccessRequirements::mustAcceptPrivacyNotice()), solo puede ver la pantalla «Aviso de privacidad» y aceptarlo, leer la
 * página pública del aviso, cambiar su contraseña (que también lo pide) o cerrar sesión; a una petición JSON o de
 * Livewire le responde 403 en lugar de redirigir, como EnsurePasswordIsChanged. Va en el grupo `web` (bootstrap/app.php),
 * después de EnsurePasswordIsChanged: quien todavía entra con su NIP lo acepta al cambiarlo. Al personal y a los invitados
 * no les hace nada.
 */
final class EnsurePrivacyNoticeAccepted
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_ROUTES = ['privacy-notice.accept', 'privacy-notice.accept.store', 'privacy-notice', 'user-password.edit', 'user-password.update', 'logout'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $request->routeIs(...self::ALLOWED_ROUTES) && app(AccessRequirements::class)->mustAcceptPrivacyNotice($user)) {
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return new JsonResponse(['message' => 'Acepta el aviso de privacidad para continuar.'], Response::HTTP_FORBIDDEN);
            }

            return redirect()->guest(route('privacy-notice.accept'));
        }

        return $next($request);
    }
}
