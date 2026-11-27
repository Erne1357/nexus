<?php

namespace App\Modules\Core\Http\Responses;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuesta de Fortify después de cambiar la contraseña. La sesión actual sigue activa con un id
 * nuevo (las demás las cierra AuthenticateSession). Vuelve a la pantalla de cambio con el estado
 * `password-updated`, donde cuelga el acuse (tarea 1.30). Si era el cambio obligatorio del primer
 * acceso o del NIP del SII (AC-12), guarda en la sesión la página que había pedido (o el inicio) para el botón «Entrar a
 * Nexus» del acuse (CONTINUE_TO).
 */
final class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    /**
     * Llave de sesión (flash) de la página a la que sigue quien acaba de hacer el cambio del primer acceso.
     */
    public const string CONTINUE_TO = 'core.password.continue_to';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->hasSession()) {
            $request->session()->regenerate(destroy: true);
        }

        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        $response = redirect()->route('user-password.edit')->with('status', Fortify::PASSWORD_UPDATED);

        if ($this->wasForcedChange($request) && $request->hasSession()) {
            $response->with(self::CONTINUE_TO, $request->session()->pull('url.intended', Fortify::redirects('password-update')));
        }

        return $response;
    }

    /**
     * UpdateUserPassword apaga `must_change_password` y deja el origen como propio en el mismo guardado que cambia la
     * contraseña (el último del usuario): si `must_change_password` cambió, o si el origen dejó de ser el NIP del SII
     * (AC-12), el cambio era el obligatorio.
     */
    private function wasForcedChange(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->wasChanged('must_change_password')
            || ($user->wasChanged('password_origin') && ($user->getPrevious()['password_origin'] ?? null) === PasswordOrigin::SiiNip->value);
    }
}
