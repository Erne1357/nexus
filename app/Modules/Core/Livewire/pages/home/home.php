<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\HomeCardsRequested;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Queries\RoleTitleQuery;
use App\Modules\Core\Queries\TermCalendarQuery;
use App\Modules\Core\Support\HomeCard;
use App\Modules\Core\Support\StageTimeline;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * «Resumen» en Mi espacio (`/resumen`; hasta la tarea 1.24.1 era el Inicio, que ahora es el mapa del campus): saludo y el bloque del perfil activo (Actor::activeRole()), con su nombre y lo que ese puesto hace
 * en Nexus según el glosario, para quien lo ocupa (RoleTitleQuery: el jefe de Servicios Escolares se nombra por su
 * unidad, AZ-02). A quien tiene al menos un rol le muestra además el bloque «Calendario»
 * (core::terms.mini-timeline): las etapas en curso y las dos que siguen del periodo activo, según TermCalendarQuery,
 * con el periodo al pie; sin periodo activo o sin etapas por ver, no aparece. Con una sola modalidad
 * (Actor::modalities()) son las de esa modalidad, que pueden tener ventana propia (M4); con varias, las generales:
 * Inicio no elige modalidad. A quien puede ver el periodo (TermPolicy) le enlaza su ficha.
 * Al personal le muestra también las tarjetas de lo que le toca hacer, que ponen los módulos al contestar
 * HomeCardsRequested (Core no los conoce): por ejemplo, la validación de catálogos abierta (CV-S13).
 * Al alumno no: su portada es mínima, el bloque de su puesto y «Mis datos» en el menú (AC-15, supuesto por confirmar).
 * Al docente tampoco (D12, confirmado el 14 de noviembre de 2026): su Inicio es solo lo suyo, y su bloque lleva su
 * acceso (usuario, correo institucional y «Cambiar contraseña»).
 *
 * El bloque de un puesto puede llevar atajos a entradas del menú (Role::homeShortcuts()), por nombre de ruta: cada uno sale solo
 * si el usuario ve esa entrada en su menú (Navigation), así Inicio no depende del módulo que la declara. El jefe de
 * Servicios Escolares, por ejemplo, ve «Materias por colocar» (tarea 1.21); los demás jefes no la ven en el menú.
 */
new #[Title('Resumen')] class extends Component
{
    /**
     * @return array{name: string, blocks: list<array{title: string, description: string, account: array{username: string, email: ?string}|null, links: list<array{label: string, url: string, icon: string}>}>, cards: list<HomeCard>, calendar: array{timeline: StageTimeline, caption: string, termUrl: ?string}|null}
     */
    public function with(Actor $context, TermCalendarQuery $calendar, RoleTitleQuery $titles, Navigation $navigation): array
    {
        $active = $context->activeRole();
        $roles = $active === null ? [] : [$active];
        $term = $active === null || ! $active->seesTermCalendarOnHome() ? null : $context->loadTerm();
        $modalities = $context->modalities();
        $windows = $term === null ? [] : $calendar->upcoming($term, $modalities->count() === 1 ? $modalities->first() : null);
        $cards = [];

        if ($active?->isStaff() === true) {
            event($requested = new HomeCardsRequested($context, $term));
            $cards = $requested->cards();
        }

        return [
            'calendar' => $windows === [] ? null : [
                'timeline' => StageTimeline::build($windows, [], today()->toImmutable()),
                'caption' => $term->name,
                'termUrl' => Gate::allows('view', $term) ? route('core.terms.show', $term) : null,
            ],
            'name' => $context->user()?->name ?? '',
            'blocks' => array_map(fn (Role $role): array => [
                ...$titles->of($context, $role),
                'account' => $role->showsAccessOnHome() ? ['username' => $context->user()->username, 'email' => $context->user()->email] : null,
                'links' => $this->links($role, $context, $navigation),
            ], $roles),
            'cards' => $cards,
        ];
    }

    /**
     * Los atajos de `$role` que el usuario ve en su menú: con permiso y disponibles en el periodo (MN-02).
     *
     * @return list<array{label: string, url: string, icon: string}>
     */
    private function links(Role $role, Actor $context, Navigation $navigation): array
    {
        $links = [];

        foreach ($role->homeShortcuts() as $route) {
            $item = $navigation->forRoute($route, $context);

            if ($item === null || ! $item->isVisibleTo($context) || ($item->whyUnavailable !== null && ($item->whyUnavailable)($context) !== null)) {
                continue;
            }

            $links[] = ['label' => $item->label, 'url' => route($item->route), 'icon' => $item->icon];
        }

        return $links;
    }
};
