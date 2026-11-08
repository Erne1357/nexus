<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Rules\OverlappingPairs;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-01: un docente no puede tener dos sesiones que se empalmen en el mismo día y periodo, en ningún campus. La base
 * lo rechaza (`group_sessions_teacher_no_overlap`); esta regla da el mensaje antes de escribir y revisa lo que se
 * pregunta sin guardar (checkSession, checkTeacher). Cada empalme se reporta en los dos grupos.
 */
final class TeacherOverlap implements Constraint
{
    public function rule(): string
    {
        return 'HC-01';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->teacherIds($state) as $teacherId) {
            $name = $state->teacher($teacherId)?->name ?? 'El docente';

            foreach (OverlappingPairs::of($state->teacherSessions($teacherId), fn (StateSession $a, StateSession $b): bool => $scope->touches($a->groupId, $b->groupId, $teacherId)) as [$mine, $other]) {
                yield Violation::hard(
                    $this->rule(),
                    "{$name} ya da {$state->labelOf($other->groupId)} el {$other->describe()}.",
                    'teacher',
                    $teacherId,
                    $mine->groupId,
                    [
                        'teacher_id' => $teacherId,
                        'session_id' => $mine->id,
                        'other_group_id' => $other->groupId,
                        'other_session_id' => $other->id,
                        'day' => $other->day,
                    ],
                );
            }
        }
    }
}
