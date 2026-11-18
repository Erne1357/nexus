<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Enums\SolverPermission;
use Illuminate\Support\Collection;

/**
 * Lo que la pantalla de corridas necesita además de las corridas (tarea 6.10): de qué carga o carrera puede ver
 * corridas quien entra y las cifras de esa carga o carrera hoy (grupos, sesiones y grupos por campus).
 */
final class RunScreenQuery
{
    /**
     * Los departamentos con carga en el periodo y la modalidad cuyas corridas ve, por clave.
     *
     * @return Collection<int, Department>
     */
    public function departmentsFor(Actor $context, Term $term, Modality $modality): Collection
    {
        return Department::query()->withTrashed()
            ->whereIn('id', AcademicLoad::query()->where('term_id', $term->id)->where('modality_id', $modality->id)->select('department_id'))
            ->orderBy('code')
            ->get()
            ->filter(fn (Department $department): bool => $context->allows(SolverPermission::DepartmentRunView, Target::of(modality: $modality->id, unit: $department->id)))
            ->values();
    }

    /**
     * Las carreras con grupos en el periodo y la modalidad cuyas corridas ve, por clave.
     *
     * @return Collection<int, Career>
     */
    public function careersFor(Actor $context, Term $term, Modality $modality): Collection
    {
        return Career::query()->withTrashed()
            ->whereIn('id', Group::query()->active()->where('term_id', $term->id)->where('modality_id', $modality->id)->select('owner_career_id'))
            ->orderBy('code')
            ->get()
            ->filter(fn (Career $career): bool => $context->allows(SolverPermission::CareerRunView, Target::of(modality: $modality->id, career: $career->id)))
            ->values();
    }

    /**
     * Las carreras del departamento que tienen grupos en el periodo y la modalidad, por clave: las que se pueden explorar
     * desde la carga.
     *
     * @return Collection<int, Career>
     */
    public function careersOfDepartment(Term $term, Modality $modality, Department $department): Collection
    {
        return Career::query()
            ->where('department_id', $department->id)
            ->whereIn('id', Group::query()->active()->where('term_id', $term->id)->where('modality_id', $modality->id)->select('owner_career_id'))
            ->orderBy('code')
            ->get();
    }

    /**
     * Los grupos sin cancelar de la carga del departamento o de la carrera, sus sesiones y los grupos por campus.
     *
     * @return array{groups: int, sessions: int, campuses: array<string, int>}
     */
    public function facts(Term $term, Modality $modality, ?Department $department, ?Career $career): array
    {
        $groups = Group::query()->active()
            ->where('groups.term_id', $term->id)
            ->where('groups.modality_id', $modality->id)
            ->when($department !== null, fn ($query) => $query->where('groups.department_id', $department->id))
            ->when($career !== null, fn ($query) => $query->where('groups.owner_career_id', $career->id));

        return [
            'groups' => (clone $groups)->count(),
            'sessions' => GroupSession::query()->whereIn('group_id', (clone $groups)->select('groups.id'))->count(),
            'campuses' => (clone $groups)->join('campuses', 'campuses.id', '=', 'groups.campus_id')
                ->groupBy('campuses.code')->orderBy('campuses.code')->selectRaw('campuses.code, count(*) as total')
                ->pluck('total', 'code')->map(fn ($total): int => (int) $total)->all(),
        ];
    }
}
