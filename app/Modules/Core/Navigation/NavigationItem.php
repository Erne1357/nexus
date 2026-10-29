<?php

namespace App\Modules\Core\Navigation;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Queries\ScopedContext;
use Closure;
use Illuminate\Support\Facades\Gate;

/**
 * Una entrada del menú. La declara un módulo en navigation() de su provider, siempre
 * con el área del menú a la que pertenece.
 *
 * Quién la ve se decide con ScopedContext (`visible`), con una ability de Gate y la policy del
 * módulo (`ability` y `arguments`), o con ambas, y entonces debe cumplir las dos; sin ninguna, la ve
 * cualquier usuario activo con sesión. Nunca con el código de un campus.
 *
 *     new NavigationItem('Campus', 'core.campuses', 'o-building-office-2', NavigationArea::Academico,
 *         ability: 'viewAny', arguments: [Campus::class])
 *     new NavigationItem('Propuestas', 'academic-load.proposals', 'o-document-text', NavigationArea::Academico,
 *         visible: static fn (ScopedContext $context): bool => $context->has(Role::Coordinador))
 *
 * Una pantalla hija que no va en el menú (la ficha de un alumno) se declara en `childRoutes` de la entrada
 * de su listado: así el menú y la banda la ubican en esa entrada.
 */
final readonly class NavigationItem
{
    /**
     * @param  string  $label  texto de la entrada, en español
     * @param  string  $route  nombre de una ruta registrada
     * @param  string  $icon  Heroicon de MaryUI (`o-home`)
     * @param  NavigationArea  $area  área del menú; el orden de las áreas es el del enum
     * @param  (Closure(ScopedContext): bool)|null  $visible  sin estado: el registro se comparte entre peticiones
     * @param  array<int, mixed>  $arguments  argumentos de la ability (p. ej. `[Campus::class]`)
     * @param  list<string>  $childRoutes  rutas de las pantallas hijas que no van en el menú (`students.show`)
     */
    public function __construct(
        public string $label,
        public string $route,
        public string $icon,
        public NavigationArea $area,
        public ?Closure $visible = null,
        public ?string $ability = null,
        public array $arguments = [],
        public array $childRoutes = [],
    ) {}

    /**
     * Si la ruta es la de la entrada o la de una de sus pantallas hijas.
     */
    public function owns(string $route): bool
    {
        return $this->route === $route || in_array($route, $this->childRoutes, true);
    }

    public function isVisibleTo(ScopedContext $context): bool
    {
        $user = $context->user();

        if ($user === null) {
            return false;
        }

        if ($this->visible !== null && ! ($this->visible)($context)) {
            return false;
        }

        return $this->ability === null || Gate::forUser($user)->allows($this->ability, $this->arguments);
    }
}
