<?php

namespace App\Modules\Core\Navigation;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Contracts\Session\Session;

/**
 * La última página que el usuario visitó de cada área del menú, en su sesión: a ella lleva el icono
 * del área (DESIGN.md, "Navigation"). La anota el menú al pintarse en una página completa; la olvida
 * SetActiveContext al cambiar el campus o el periodo, porque una ficha de otro campus ya no se abriría.
 *
 * No se registra en el contenedor: cada petición arma la suya con su sesión y su contexto (Octane).
 */
final readonly class RecentPages
{
    private const string KEY = 'core.recent_pages';

    public function __construct(
        private Session $session,
        private Navigation $navigation,
        private ScopedContext $context,
    ) {}

    /**
     * Anota la página como la última de su área si su ruta es la de una entrada, o la de una pantalla
     * hija de una entrada (Navigation::forRoute), que el usuario ve. Se guarda con una sola barra al
     * inicio: una petición a `//host/...` no puede quedar como enlace a otro host.
     *
     * @param  string  $uri  ruta relativa de la petición con su consulta (`/students/<id>?tab=kardex`)
     */
    public function remember(string $route, string $uri): void
    {
        $item = $this->navigation->forRoute($route);

        if ($item !== null && $item->isVisibleTo($this->context)) {
            $this->session->put(self::KEY.'.'.$item->area->value, '/'.ltrim($uri, '/'));
        }
    }

    /**
     * La última página que el usuario visitó del área; null si no ha visitado ninguna.
     */
    public function last(NavigationArea $area): ?string
    {
        $uri = $this->session->get(self::KEY.'.'.$area->value);

        return is_string($uri) ? $uri : null;
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
