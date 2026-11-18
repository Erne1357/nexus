<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Rules\Timeline;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-01: las materias de un mismo semestre reticular quedan en horas consecutivas, sin huecos, para el alumno tipo. El
 * alumno tipo es cada cohorte (las de Rules/Cohorts o las del contrato del motor, en SoftContext): los grupos que un
 * mismo alumno toma juntos.
 *
 * Unidad (supuesto por confirmar): una hora de hueco entre las sesiones de los grupos activos de una cohorte en un día,
 * junto a una sesión de un grupo del alcance (lo que se programa); una fracción de hora cuenta como hora.
 */
final class StudentGaps implements SoftRule
{
    public function rule(): string
    {
        return 'SC-01';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $scored = [];

        foreach ($scope->groupIds() as $groupId) {
            foreach ($context->cohorts[$groupId] ?? [] as $key) {
                $scored[$key] = true;
            }
        }

        $hours = 0;

        foreach (array_keys($scored) as $key) {
            $sessions = [];

            foreach ($context->membersOf($key) as $groupId) {
                if ($state->group($groupId)?->isActive) {
                    foreach ($state->sessionsOf($groupId) as $session) {
                        $sessions[] = $session;
                    }
                }
            }

            foreach (Timeline::byDay($sessions) as $day) {
                foreach (Timeline::gaps($day) as [$before, $after, $minutes]) {
                    if ($scope->touches($before->groupId, $after->groupId)) {
                        $hours += Timeline::hours($minutes);
                    }
                }
            }
        }

        return $hours;
    }
}
