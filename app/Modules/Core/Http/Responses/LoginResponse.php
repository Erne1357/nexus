<?php

namespace App\Modules\Core\Http\Responses;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Core\Queries\PageTrailQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuesta de Fortify al entrar. El formulario de la pantalla de acceso se manda pidiendo JSON (resources/js/login.js,
 * tarea 1.23): la respuesta trae la ficha de la credencial (CredentialQuery, la misma de la banda) para llenarla y
 * llevarla a la banda, la dirección a la que sigue (la que se había pedido o el inicio, como la redirección) y si la
 * siguiente página tiene banda, con su ruta (PageTrailQuery: el área y la pantalla, como las pinta la banda), que ocupa su
 * lugar en la banda de llegada para que la credencial caiga donde estará (#694). Sin banda: con cambio de contraseña
 * pendiente, o si la página no la tiene (una descarga, una página pública, una de otro puesto). Sin JSON redirige como
 * siempre.
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
        $page = $user instanceof User && $user->must_change_password ? null : app(PageTrailQuery::class)->forUrl($target->getTargetUrl(), app(Actor::class));

        return new JsonResponse([
            'redirect' => $target->getTargetUrl(),
            'band' => $page !== null,
            'page' => $page,
            'credential' => app(CredentialQuery::class)->of(app(Actor::class)),
        ]);
    }
}
