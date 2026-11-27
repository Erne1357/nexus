<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Rules\SubjectTeacherHours;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Los grupos que entran a una selección (tarea 1.33, diseño 6.12): los no cancelados de sus cargas incluidas
 * (`staffing_selection_loads`), de todos sus campus (M1). También dice qué selección viva tiene una carga.
 */
final class SelectionGroupsQuery
{
    /**
     * @return Builder<Group>
     */
    public function of(Selection $selection): Builder
    {
        return Group::query()
            ->where('groups.status', '!=', GroupStatus::Cancelled->value)
            ->whereExists(fn (QueryBuilder $query) => $query->selectRaw('1')
                ->from('staffing_selection_loads')
                ->join('academic_loads', 'academic_loads.id', '=', 'staffing_selection_loads.academic_load_id')
                ->whereColumn('academic_loads.term_id', 'groups.term_id')
                ->whereColumn('academic_loads.modality_id', 'groups.modality_id')
                ->whereColumn('academic_loads.department_id', 'groups.department_id')
                ->where('staffing_selection_loads.selection_id', $selection->getKey()));
    }

    /**
     * Las horas (teoría y práctica de la materia) de los grupos de la selección que tiene cada docente, en vivo, por id
     * del docente (los que no tienen grupos no aparecen). Una consulta.
     *
     * @param  list<string>|null  $teacherIds  solo estos docentes (null: todos los que tienen grupos)
     * @return Collection<string, int>
     */
    public function hoursByTeacher(Selection $selection, ?array $teacherIds = null): Collection
    {
        if ($teacherIds === []) {
            return collect();
        }

        $query = $this->of($selection)
            ->whereNotNull('groups.teacher_id')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->groupBy('groups.teacher_id')
            ->selectRaw('groups.teacher_id, sum('.SubjectTeacherHours::SQL.') as hours');

        if ($teacherIds !== null) {
            $query->whereIn('groups.teacher_id', $teacherIds);
        }

        return $query->toBase()->pluck('hours', 'teacher_id')->map(fn ($hours): int => (int) $hours);
    }

    /**
     * Si la carga del grupo es una de las incluidas en la selección.
     */
    public function includesLoad(Selection $selection, ?AcademicLoad $load): bool
    {
        return $load !== null && $selection->loads()->whereKey($load->getKey())->exists();
    }

    /**
     * La selección viva (publicada, en curso o en ronda extra) que incluye la carga, o null.
     */
    public function liveSelectionFor(?AcademicLoad $load): ?Selection
    {
        return $this->selectionFor($load, SelectionStatus::live());
    }

    /**
     * La selección terminada que incluye la carga, o null: su orden sigue reemplazando al ranking de esa carga (HC-16,
     * decisión del coordinador del riel del 24 de noviembre de 2026).
     */
    public function finishedSelectionFor(?AcademicLoad $load): ?Selection
    {
        return $this->selectionFor($load, [SelectionStatus::Finished]);
    }

    /**
     * @param  list<SelectionStatus>  $statuses
     */
    private function selectionFor(?AcademicLoad $load, array $statuses): ?Selection
    {
        if ($load === null) {
            return null;
        }

        return Selection::query()
            ->whereIn('status', array_map(fn (SelectionStatus $status): string => $status->value, $statuses))
            ->whereHas('loads', fn (Builder $loads) => $loads->whereKey($load->getKey()))
            ->first();
    }
}
