<?php

namespace App\Modules\Core\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Después de cambiar la contraseña, el usuario sigue a la página que había pedido antes del
 * cambio obligatorio (o al inicio), en lugar de volver al formulario como hace Fortify.
 */
final class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 200)
            : redirect()->intended(Fortify::redirects('password-update'))->with('status', Fortify::PASSWORD_UPDATED);
    }
}
