<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TermCalendarQuery;
use App\Modules\Core\Support\StageTimeline;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página de inicio: saludo y un bloque por cada rol que el usuario ejerce (ScopedContext::roles()),
 * con su nombre (Role::label()) y lo que ese rol hace en Nexus según el glosario. El admin, que
 * tiene todos los roles, ve solo el suyo. A quien tiene al menos un rol le muestra además el bloque «Calendario»
 * (core::terms.mini-timeline): las etapas en curso y las dos que siguen del periodo y el campus activos, según
 * TermCalendarQuery; sin periodo activo o sin etapas por ver, no aparece. A quien puede ver el periodo (TermPolicy) le enlaza su ficha.
 */
new #[Title('Inicio')] class extends Component
{
    /**
     * @return array{name: string, blocks: list<array{title: string, description: string}>, calendar: array{timeline: StageTimeline, caption: string, termUrl: ?string}|null}
     */
    public function with(ScopedContext $context, TermCalendarQuery $calendar): array
    {
        $roles = in_array(Role::Admin, $context->roles(), true) ? [Role::Admin] : $context->roles();
        $term = $roles === [] ? null : $context->activeTerm();
        $campus = $context->activeCampus();
        $windows = $term === null ? [] : $calendar->upcoming($term, $campus);

        return [
            'calendar' => $windows === [] ? null : [
                'timeline' => StageTimeline::build($windows, [], today()->toImmutable()),
                'caption' => $term->name.($campus === null ? '' : " · {$campus->name}"),
                'termUrl' => Gate::allows('view', $term) ? route('core.terms.show', $term) : null,
            ],
            'name' => $context->user()?->name ?? '',
            'blocks' => array_map(fn (Role $role): array => [
                'title' => $role->label(),
                'description' => $this->description($role),
            ], $roles),
        ];
    }

    private function description(Role $role): string
    {
        return match ($role) {
            Role::Admin => 'Tienes acceso a todo Nexus, en todos los campus. Tus acciones quedan en la bitácora como las de cualquier usuario.',
            Role::Subdireccion => 'Si el campus lo tiene configurado, das el visto bueno final antes de publicar la carga académica.',
            Role::JefeDepartamento => 'Validas los catálogos de tu departamento, asignas docentes y salones a los grupos de sus materias y cierras su carga.',
            Role::JefeProyectoDocente => 'Junto con el jefe de departamento, asignas docentes a los grupos según la prioridad de base.',
            Role::Coordinador => 'Analizas el avance de los alumnos de tu carrera, propones qué materias y cuántos grupos abrir y armas la propuesta de horarios por semestre.',
            Role::Docente => 'Consultas tu propia carga: los grupos que impartes y sus horarios.',
        };
    }
};
