<?php

namespace App\Modules\Solver\Rules;

use App\Modules\Scheduling\Rules\Cohorts;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateGroup;

/**
 * Los grupos activos de una modalidad agrupados por departamento y sus cohortes, con la definición de
 * Scheduling\Rules\Cohorts (la misma de BuildSolverInput). Es la base de RunOrderQuery y CommonCoreWarningQuery: nunca
 * una lista fija de departamentos, todo sale de las cohortes. Puro: solo lee la foto.
 */
final class DepartmentCohorts
{
    /**
     * @param  array<string, StateGroup>  $groups  Grupos activos de la modalidad, por id.
     * @param  array<string, list<string>>  $cohorts  Claves de cohorte por id de grupo.
     */
    private function __construct(public readonly array $groups, public readonly array $cohorts) {}

    public static function of(ScheduleState $state, string $modalityId): self
    {
        $groups = [];

        foreach ($state->groups() as $group) {
            if ($group->isActive && $group->modalityId === $modalityId) {
                $groups[$group->id] = $group;
            }
        }

        return new self($groups, Cohorts::of($state, $groups));
    }

    /**
     * Las claves de cohorte que lleva algún grupo del departamento.
     *
     * @return array<string, true>
     */
    public function keysOf(string $departmentId): array
    {
        $keys = [];

        foreach ($this->groups as $group) {
            if ($group->departmentId === $departmentId) {
                foreach ($this->cohorts[$group->id] as $key) {
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
            && array_filter($this->cohorts[$group->id], fn (string $key): bool => isset($keys[$key])) !== []));
    }
}
