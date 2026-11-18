<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-02: las sesiones de un grupo se reparten en días distintos (no 5 horas el mismo día).
 *
 * Unidad (supuesto por confirmar): una hora de un grupo en un día por encima del reparto parejo, que es el techo de sus
 * horas semanales (las de su materia; sin materia, las que tiene programadas) entre los días de su modalidad. Una
 * materia de 5 h en una modalidad de lunes a viernes: más de 1 h en un día cuenta, y 5 h el mismo día suman 4. Una
 * fracción de hora cuenta como hora. Cuenta los grupos activos del alcance.
 */
final class GroupDaySpread implements SoftRule
{
    public function rule(): string
    {
        return 'SC-02';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $hours = 0;

        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);
            $sessions = $state->sessionsOf($groupId);

            if ($group === null || ! $group->isActive || $sessions === []) {
                continue;
            }

            $minutes = [];

            foreach ($sessions as $session) {
                $minutes[$session->day] = ($minutes[$session->day] ?? 0) + $session->minutes();
            }

            $weekly = $state->subject($group->subjectId)?->weeklyHours() ?? Timeline::hours(array_sum($minutes));
            $days = max(1, count($state->profile($group->modalityId)->days));
            $share = max(1, intdiv($weekly + $days - 1, $days));

            foreach ($minutes as $dayMinutes) {
                $hours += max(0, Timeline::hours($dayMinutes) - $share);
            }
        }

        return $hours;
    }
}
