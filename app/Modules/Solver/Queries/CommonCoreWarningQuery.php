<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Solver\Rules\DepartmentCohorts;

/**
 * Aviso de tronco común para la pantalla de corridas (SV-20; tarea 6.9). Decisión del usuario, 17 de noviembre de
 * 2026: el tronco común primero no se exige; cada departamento corre cuando quiere con lo aceptado como fijo, y la
 * pantalla avisa.
 *
 * Por cada otra carga cuyos grupos comparten al menos una cohorte con los grupos de esta carga (la definición de
 * Scheduling\Rules\Cohorts), cuenta esos grupos compartidos y cuántos no tienen sesiones. Solo entran las cargas con
 * al menos uno sin sesiones, de más a menos. Nunca usa una lista fija de departamentos.
 */
final class CommonCoreWarningQuery
{
    public function __construct(private readonly ScheduleStateQuery $states) {}

    /**
     * @return list<array{department: Department, groups: int, without_sessions: int}>
     */
    public function forDepartment(Term $term, Modality $modality, Department $department): array
    {
        $state = $this->states->forModality($term, $modality->id);
        $cohorts = DepartmentCohorts::of($state, $modality->id);

        $counts = [];

        foreach ($cohorts->sharedWith($department->id, $cohorts->keysOf($department->id)) as $group) {
            $counts[$group->departmentId]['groups'] = ($counts[$group->departmentId]['groups'] ?? 0) + 1;
            $counts[$group->departmentId]['without'] = ($counts[$group->departmentId]['without'] ?? 0) + ($state->sessionsOf($group->id) === [] ? 1 : 0);
        }

        $counts = array_filter($counts, fn (array $count): bool => $count['without'] > 0);
        $departments = Department::query()->withTrashed()->whereIn('id', array_keys($counts))->get()->keyBy('id');

        $rows = array_values(array_map(fn (string $id): array => [
            'department' => $departments[$id],
            'groups' => $counts[$id]['groups'],
            'without_sessions' => $counts[$id]['without'],
        ], array_keys($counts)));

        usort($rows, fn (array $a, array $b): int => [$b['without_sessions'], $a['department']->name, $a['department']->id]
            <=> [$a['without_sessions'], $b['department']->name, $b['department']->id]);

        return $rows;
    }
}
