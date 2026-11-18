<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Solver\Rules\DepartmentCohorts;

/**
 * El orden sugerido para correr las cargas de una modalidad (SV-21; tarea 6.9). Es solo una sugerencia: no impone
 * nada (decisión del usuario, 17 de noviembre de 2026).
 *
 * Primero las cargas que más cohortes comparten con otras cargas y, en empate, las de más grupos. Por qué: lo que
 * muchos comparten (por ejemplo el tronco común) conviene fijarlo primero, porque cada carga que corre después lo toma
 * como fijo y ya no lo mueve. Las cohortes son las de Scheduling\Rules\Cohorts; ninguna lista fija de departamentos.
 */
final class RunOrderQuery
{
    public function __construct(private readonly ScheduleStateQuery $states) {}

    /**
     * @return list<array{department: Department, load_id: string, shared_cohorts: int, groups: int, with_sessions: int}>
     */
    public function forTerm(Term $term, Modality $modality): array
    {
        $state = $this->states->forModality($term, $modality->id);
        $cohorts = DepartmentCohorts::of($state, $modality->id);

        $rows = [];

        foreach (AcademicLoad::query()->with('department')->where('term_id', $term->id)->where('modality_id', $modality->id)->get() as $load) {
            $own = array_filter($cohorts->groups, fn ($group): bool => $group->departmentId === $load->department_id);
            $keys = $cohorts->keysOf($load->department_id);
            $shared = [];

            foreach ($cohorts->sharedWith($load->department_id, $keys) as $other) {
                foreach ($cohorts->cohorts[$other->id] as $key) {
                    if (isset($keys[$key])) {
                        $shared[$key] = true;
                    }
                }
            }

            $rows[] = [
                'department' => $load->department,
                'load_id' => $load->id,
                'shared_cohorts' => count($shared),
                'groups' => count($own),
                'with_sessions' => count(array_filter($own, fn ($group): bool => $state->sessionsOf($group->id) !== [])),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['shared_cohorts'], $b['groups'], $a['department']->name, $a['load_id']]
            <=> [$a['shared_cohorts'], $a['groups'], $b['department']->name, $b['load_id']]);

        return $rows;
    }
}
