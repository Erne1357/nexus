<?php

namespace App\Modules\Core\Http\Responses;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CredentialQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuesta de Fortify al entrar. El formulario de la pantalla de acceso se manda pidiendo JSON (resources/js/login.js,
 * tarea 1.23): la respuesta trae la ficha de la credencial (CredentialQuery, la misma de la banda) para llenarla y
 * llevarla a la banda, la dirección a la que sigue (la que se había pedido o el inicio, como la redirección) y si la
 * siguiente página tiene banda: con el cambio de contraseña pendiente no la tiene. Sin JSON redirige como siempre.
 *
 * No guarda estado: el contexto se pide en cada respuesta (Octane).
 */
final class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $target = redirect()->intended(Fortify::redirects('login'));

        if (! $request->wantsJson()) {
            return $target;
        }

        $user = $request->user();

        return new JsonResponse([
            'redirect' => $target->getTargetUrl(),
            'band' => ! ($user instanceof User && $user->must_change_password),
            'credential' => app(CredentialQuery::class)->of(app(Actor::class)),
        ]);
    }
}
