<?php

namespace App\Modules\Core\Navigation;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use Closure;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Una entrada del menú. La declara un módulo en navigation() de su provider, siempre
 * con el área del menú a la que pertenece y, si el área se agrupa, con su grupo (MN-01).
 *
 * Quién la ve se decide con Actor (`visible`), con una ability de Gate y la policy del
 * módulo (`ability` y `arguments`), o con ambas, y entonces debe cumplir las dos; sin ninguna, la ve
 * cualquier usuario activo con sesión. Nunca con el código de un campus.
 *
 *     new NavigationItem('Campus', 'core.campuses', 'o-building-office-2', NavigationArea::Administracion,
 *         group: NavigationGroup::Institucion, ability: 'viewAny', arguments: [Campus::class])
 *     new NavigationItem('Propuesta de carrera', 'academic-load.proposal', 'o-clipboard-document-list', NavigationArea::Academico,
 *         group: NavigationGroup::Propuesta,
 *         visible: static fn (Actor $context): bool => $context->allowsAny(AcademicLoadPermission::ProposalView))
 *
 * Además del permiso, una entrada puede depender del periodo activo (MN-02): `whyUnavailable` da null si
 * está disponible o el motivo si no («No hay carga en curso en Ene-Jun 2027»). Sin estar disponible, solo
 * la ve en el menú quien tiene `core.navigation.view_unavailable` (el admin), marcada con el motivo.
 * Varias entradas con la misma condición comparten la misma closure: Navigation la evalúa una vez.
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
     * @param  (Closure(Actor): bool)|null  $visible  sin estado: el registro se comparte entre peticiones
     * @param  array<int, mixed>  $arguments  argumentos de la ability (p. ej. `[Campus::class]`)
     * @param  list<string>  $childRoutes  rutas de las pantallas hijas que no van en el menú (`students.show`)
     * @param  NavigationGroup|null  $group  grupo dentro del área; debe ser del área de la entrada
     * @param  int  $order  orden dentro de su grupo: menor primero; a igual orden, el del registro
     * @param  (Closure(Actor): ?string)|null  $whyUnavailable  sin estado: null si está disponible en el periodo activo, o el motivo
     *
     * @throws InvalidArgumentException si el grupo es de otra área
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
        public ?NavigationGroup $group = null,
        public int $order = 0,
        public ?Closure $whyUnavailable = null,
    ) {
        if ($group !== null && $group->area() !== $area) {
            throw new InvalidArgumentException("El grupo {$group->label()} es del área {$group->area()->label()}, no de {$area->label()}: «{$label}».");
        }
    }

    /**
     * Si la ruta es la de la entrada o la de una de sus pantallas hijas.
     */
    public function owns(string $route): bool
    {
        return $this->route === $route || in_array($route, $this->childRoutes, true);
    }

    /**
     * Si el usuario del contexto tiene permiso de verla; si está disponible en el periodo lo decide
     * Navigation con `whyUnavailable`.
     */
    public function isVisibleTo(Actor $context): bool
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
