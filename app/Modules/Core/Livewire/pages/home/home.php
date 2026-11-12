<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\RoleTitleQuery;
use App\Modules\Core\Queries\TermCalendarQuery;
use App\Modules\Core\Support\StageTimeline;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página de inicio: saludo y el bloque del perfil activo (Actor::activeRole()), con su nombre y lo que ese puesto hace
 * en Nexus según el glosario, para quien lo ocupa (RoleTitleQuery: el jefe de Servicios Escolares se nombra por su
 * unidad, AZ-02). A quien tiene al menos un rol le muestra además el bloque «Calendario»
 * (core::terms.mini-timeline): las etapas en curso y las dos que siguen del periodo activo, según TermCalendarQuery,
 * con el periodo al pie; sin periodo activo o sin etapas por ver, no aparece. Con una sola modalidad
 * (Actor::modalities()) son las de esa modalidad, que pueden tener ventana propia (M4); con varias, las generales:
 * Inicio no elige modalidad. A quien puede ver el periodo (TermPolicy) le enlaza su ficha.
 * Al alumno no: su portada es mínima, el bloque de su puesto y «Mis datos» en el menú (AC-15, supuesto por confirmar).
 */
new #[Title('Inicio')] class extends Component
{
    /**
     * @return array{name: string, blocks: list<array{title: string, description: string}>, calendar: array{timeline: StageTimeline, caption: string, termUrl: ?string}|null}
     */
    public function with(Actor $context, TermCalendarQuery $calendar, RoleTitleQuery $titles): array
    {
        $active = $context->activeRole();
        $roles = $active === null ? [] : [$active];
        $term = $active === null || ! $active->isStaff() ? null : $context->activeTerm();
        $modalities = $context->modalities();
        $windows = $term === null ? [] : $calendar->upcoming($term, $modalities->count() === 1 ? $modalities->first() : null);

        return [
            'calendar' => $windows === [] ? null : [
                'timeline' => StageTimeline::build($windows, [], today()->toImmutable()),
                'caption' => $term->name,
                'termUrl' => Gate::allows('view', $term) ? route('core.terms.show', $term) : null,
            ],
            'name' => $context->user()?->name ?? '',
            'blocks' => array_map(fn (Role $role): array => $titles->of($context, $role), $roles),
        ];
    }
};
