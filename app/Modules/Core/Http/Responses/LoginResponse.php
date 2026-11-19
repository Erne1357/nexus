<?php

namespace App\Modules\Core\Http\Responses;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Queries\CredentialQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Livewire\Features\SupportPageComponents\BaseTitle;
use Livewire\Mechanisms\HandleRouting\LivewirePageController;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Respuesta de Fortify al entrar. El formulario de la pantalla de acceso se manda pidiendo JSON (resources/js/login.js,
 * tarea 1.23): la respuesta trae la ficha de la credencial (CredentialQuery, la misma de la banda) para llenarla y
 * llevarla a la banda, la dirección a la que sigue (la que se había pedido o el inicio, como la redirección) y si la
 * siguiente página tiene banda: solo una página de Livewire (el layout de la aplicación) y sin cambio de contraseña
 * pendiente; una descarga o una ruta que no existe no la tienen. Con banda trae también su ruta (área y pantalla, como la
 * pinta core::components.band), que ocupa su lugar en la banda de llegada para que la credencial caiga donde estará
 * (tarea 1.23, #694). Sin JSON redirige como siempre.
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
        $page = $user instanceof User && $user->must_change_password ? null : $this->shellPage($target->getTargetUrl());

        return new JsonResponse([
            'redirect' => $target->getTargetUrl(),
            'band' => $page !== null,
            'page' => $page === null ? null : $this->trail($page),
            'credential' => app(CredentialQuery::class)->of(app(Actor::class)),
        ]);
    }

    /**
     * La ruta de la dirección si es una página completa de Livewire, que con sesión se pinta en el layout de la
     * aplicación con su banda; null si es otra cosa (una descarga) o no existe.
     */
    private function shellPage(string $url): ?Route
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($url));
        } catch (Throwable) {
            return null;
        }

        return $route->getActionName() === LivewirePageController::class ? $route : null;
    }

    /**
     * Lo que la banda de esa página pone después de «Nexus» (core::components.band): el área de su entrada del menú y
     * el título de la pantalla (su #[Title] o, sin él, el nombre de la entrada).
     *
     * @return array{area: ?string, title: ?string}
     */
    private function trail(Route $route): array
    {
        $item = $route->getName() === null ? null : app(Navigation::class)->forRoute($route->getName(), app(Actor::class));

        return ['area' => $item?->area->label(), 'title' => $this->titleOf($route) ?? $item?->label];
    }

    private function titleOf(Route $route): ?string
    {
        try {
            $class = app('livewire.factory')->resolveComponentClass($route->getAction('livewire_component'));
            $title = (new ReflectionClass($class))->getAttributes(BaseTitle::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        } catch (Throwable) {
            return null;
        }

        return $title?->newInstance()->content;
    }
}
