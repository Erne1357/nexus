<?php

namespace App\Modules\Solver\Rules;

use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\Cohorts;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;

/**
 * Los grupos activos de una modalidad agrupados por departamento y sus cohortes, con la definición de
 * Scheduling\Rules\Cohorts (la misma de BuildSolverInput). Es la base de RunOrderQuery y CommonCoreWarningQuery: nunca
 * una lista fija de departamentos, todo sale de las cohortes.
 *
 * Cada grupo lleva dos señales (SV-20):
 * - La cohorte de Cohorts (HC-03 hoy; los paquetes de primer semestre entran por ahí cuando llegue la 6.5a). Solo
 *   une a los grupos únicos de su materia en el plan, semestre y campus.
 * - Una señal más débil, el «alumno tipo amplio»: `plan:<plan>:<semestre>:<campus>`, la misma cadena que la cohorte de HC-03, por la carrera dueña del grupo
 *   (su plan y semestre) y por cada retícula de cada carrera con cupo que tiene la materia, aunque la materia tenga
 *   varios grupos (el tronco común). Sirve para avisar y ordenar, no para el HC-03 del motor.
 *
 * `keys` junta las dos sin repetir: al ser la misma cadena, una cohorte de HC-03 y la llave amplia del mismo plan, semestre y campus coinciden. Lee la foto y, para las retículas de las carreras con cupo, study_plan_subjects.
 */
final class DepartmentCohorts
{
    /**
     * @param  array<string, StateGroup>  $groups  Grupos activos de la modalidad, por id.
     * @param  array<string, list<string>>  $cohorts  Claves de cohorte de Cohorts por id de grupo.
     * @param  array<string, list<string>>  $keys  Cohortes y llaves de alumno tipo amplio por id de grupo.
     */
    private function __construct(public readonly array $groups, public readonly array $cohorts, public readonly array $keys) {}

    public static function of(ScheduleState $state, string $modalityId): self
    {
        $groups = [];

        foreach ($state->groups() as $group) {
            if ($group->isActive && $group->modalityId === $modalityId) {
                $groups[$group->id] = $group;
            }
        }

        $cohorts = Cohorts::of($state, $groups);

        return new self($groups, $cohorts, self::withWideKeys($state, $groups, $cohorts));
    }

    /**
     * @param  array<string, StateGroup>  $groups
     * @param  array<string, list<string>>  $cohorts
     * @return array<string, list<string>>
     */
    private static function withWideKeys(ScheduleState $state, array $groups, array $cohorts): array
    {
        $keys = [];

        foreach ($groups as $group) {
            $keys[$group->id] = [...$cohorts[$group->id], "plan:{$group->studyPlanId}:{$group->semester}:{$state->campusCode($group->campusId)}"];
        }

        GroupQuota::query()
            ->join('groups', 'groups.id', '=', 'group_quotas.group_id')
            ->join('study_plans', fn ($join) => $join->on('study_plans.career_id', '=', 'group_quotas.career_id')->whereNull('study_plans.deleted_at'))
            ->join('study_plan_subjects', fn ($join) => $join->on('study_plan_subjects.study_plan_id', '=', 'study_plans.id')
                ->on('study_plan_subjects.subject_id', '=', 'groups.subject_id')
                ->whereNull('study_plan_subjects.deleted_at'))
            ->whereIn('group_quotas.group_id', array_keys($groups))
            ->whereColumn('group_quotas.career_id', '<>', 'groups.owner_career_id')
            ->toBase()
            ->get(['group_quotas.group_id', 'study_plans.id as plan_id', 'study_plan_subjects.semester'])
            ->each(function (object $row) use (&$keys, $groups, $state): void {
                $keys[$row->group_id][] = "plan:{$row->plan_id}:{$row->semester}:{$state->campusCode($groups[$row->group_id]->campusId)}";
            });

        return array_map(fn (array $list): array => array_values(array_unique($list)), $keys);
    }

    /**
     * Las claves (cohorte o llave amplia) que lleva algún grupo del departamento.
     *
     * @return array<string, true>
     */
    public function keysOf(string $departmentId): array
    {
        $keys = [];

        foreach ($this->groups as $group) {
            if ($group->departmentId === $departmentId) {
                foreach ($this->keys[$group->id] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Los grupos de otros departamentos que llevan alguna de las claves.
     *
     * @param  array<string, true>  $keys
     * @return list<StateGroup>
     */
    public function sharedWith(string $departmentId, array $keys): array
    {
        return array_values(array_filter($this->groups, fn (StateGroup $group): bool => $group->departmentId !== $departmentId
            && array_filter($this->keys[$group->id], fn (string $key): bool => isset($keys[$key])) !== []));
    }
}
