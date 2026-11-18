<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-05: un docente tiene sus sesiones compactas (pocos huecos por día), en todos sus campus y modalidades.
 *
 * Unidad (supuesto por confirmar): una hora de hueco del docente en un día, entre dos de sus sesiones de las que al
 * menos una toca el alcance (es de un grupo del alcance, o el docente está en el alcance); una fracción cuenta como
 * hora.
 */
final class TeacherGaps implements SoftRule
{
    public function rule(): string
    {
        return 'SC-05';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $hours = 0;

        foreach ($scope->teacherIds($state) as $teacherId) {
            foreach (Timeline::byDay($state->teacherSessions($teacherId)) as $day) {
                foreach (Timeline::gaps($day) as [$before, $after, $minutes]) {
                    if ($scope->touches($before->groupId, $after->groupId, $teacherId)) {
                        $hours += Timeline::hours($minutes);
                    }
                }
            }
        }

        return $hours;
    }
}
