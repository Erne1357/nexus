<?php

namespace App\Modules\Core\Http\Responses;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\CredentialQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Livewire\Mechanisms\HandleRouting\LivewirePageController;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Respuesta de Fortify al entrar. El formulario de la pantalla de acceso se manda pidiendo JSON (resources/js/login.js,
 * tarea 1.23): la respuesta trae la ficha de la credencial (CredentialQuery, la misma de la banda) para llenarla y
 * llevarla a la banda, la dirección a la que sigue (la que se había pedido o el inicio, como la redirección) y si la
 * siguiente página tiene banda: solo una página de Livewire (el layout de la aplicación) y sin cambio de contraseña
 * pendiente; una descarga o una ruta que no existe no la tienen. Sin JSON redirige como siempre.
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
            'band' => ! ($user instanceof User && $user->must_change_password) && $this->opensShell($target->getTargetUrl()),
            'credential' => app(CredentialQuery::class)->of(app(Actor::class)),
        ]);
    }

    /**
     * Si la dirección es una página completa de Livewire, que con sesión se pinta en el layout de la aplicación con su
     * banda.
     */
    private function opensShell(string $url): bool
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($url));
        } catch (Throwable) {
            return false;
        }

        return $route->getActionName() === LivewirePageController::class;
    }
}
