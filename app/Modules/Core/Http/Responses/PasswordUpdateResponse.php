<?php

namespace App\Modules\Core\Http\Responses;

use App\Modules\Core\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuesta de Fortify después de cambiar la contraseña. La sesión actual sigue activa con un id
 * nuevo (las demás las cierra AuthenticateSession). Si era el cambio obligatorio del primer
 * acceso, el usuario sigue a la página que había pedido (o al inicio); si fue voluntario, vuelve
 * a la pantalla de cambio con el estado `password-updated`.
 */
final class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
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

        return $this->wasForcedChange($request)
            ? redirect()->intended(Fortify::redirects('password-update'))
            : back()->with('status', Fortify::PASSWORD_UPDATED);
    }

    /**
     * UpdateUserPassword apaga `must_change_password` en el mismo guardado que cambia la
     * contraseña; si cambió en ese guardado, el cambio era el obligatorio.
     */
    private function wasForcedChange(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->wasChanged('must_change_password');
    }
}
