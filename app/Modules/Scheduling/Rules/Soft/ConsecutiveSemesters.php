<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-07: las materias de semestres consecutivos de una carrera no se empalman entre sí, para los alumnos irregulares.
 * Cuentan los grupos que son la única opción de su materia, que son las cohortes de plan del alumno tipo
 * (`plan:<plan>:<semestre>:<campus>`, Rules/Cohorts o el contrato del motor, en SoftContext): donde la materia tiene
 * otro grupo, el alumno irregular puede elegir (supuesto por confirmar).
 *
 * Unidad (supuesto por confirmar): una hora de empalme entre dos grupos activos de cohortes del mismo plan y campus en
 * semestres seguidos (N y N+1); una fracción cuenta como hora. Cada par se cuenta una vez si alguno de los dos es del
 * alcance.
 */
final class ConsecutiveSemesters implements SoftRule
{
    public function rule(): string
    {
        return 'SC-07';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $seen = [];
        $hours = 0;

        foreach ($scope->groupIds() as $groupId) {
            if (! $state->group($groupId)?->isActive) {
                continue;
            }

            foreach ($context->cohorts[$groupId] ?? [] as $key) {
                $parts = explode(':', $key);

                if ($parts[0] !== 'plan' || count($parts) !== 4) {
                    continue;
                }

                [, $plan, $semester, $campus] = $parts;

                foreach ([(int) $semester - 1, (int) $semester + 1] as $next) {
                    foreach ($context->membersOf("plan:{$plan}:{$next}:{$campus}") as $otherId) {
                        if (! $state->group($otherId)?->isActive) {
                            continue;
                        }

                        $pair = $groupId < $otherId ? "{$groupId}|{$otherId}" : "{$otherId}|{$groupId}";

                        if (isset($seen[$pair])) {
                            continue;
                        }

                        $seen[$pair] = true;
                        $minutes = 0;

                        foreach ($state->sessionsOf($groupId) as $mine) {
                            foreach ($state->sessionsOf($otherId) as $theirs) {
                                $minutes += Timeline::overlap($mine, $theirs);
                            }
                        }

                        $hours += Timeline::hours($minutes);
                    }
                }
            }
        }

        return $hours;
    }
}
