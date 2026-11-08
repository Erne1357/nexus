<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-09: un docente no puede ser asignado en bloques marcados `unavailable` en su disponibilidad del periodo. Un
 * bloque contiguo a la sesión no la empalma.
 */
final class TeacherAvailability implements Constraint
{
    public function rule(): string
    {
        return 'HC-09';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);
            $teacher = $group === null || ! $group->isActive || $group->teacherId === null ? null : $state->teacher($group->teacherId);

            if ($teacher === null) {
                continue;
            }

            foreach ($state->sessionsOf($groupId) as $session) {
                foreach ($teacher->unavailable as [$day, $start, $end]) {
                    if ($day !== $session->day || ! Minutes::overlap($session->start, $session->end, $start, $end)) {
                        continue;
                    }

                    yield Violation::hard(
                        $this->rule(),
                        "{$teacher->name} no está disponible el {$session->describe()}.",
                        'teacher',
                        $teacher->id,
                        $groupId,
                        [
                            'day' => $session->day,
                            'starts_at' => Minutes::format($session->start),
                            'ends_at' => Minutes::format($session->end),
                        ],
                    );

                    break;
                }
            }
        }
    }
}
