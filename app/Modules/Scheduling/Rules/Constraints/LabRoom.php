<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-06: una materia con `needs_lab` solo puede tener sesiones prácticas en salones de tipo laboratorio, taller o
 * sala de cómputo. Las sesiones sin salón no violan: todavía no hay dónde fallar.
 */
final class LabRoom implements Constraint
{
    public function rule(): string
    {
        return 'HC-06';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->roomIds($state) as $roomId) {
            $room = $state->room($roomId);

            if ($room === null || $room->type->isPracticeSpace()) {
                continue;
            }

            foreach ($state->roomSessions($roomId) as $session) {
                $group = $state->group($session->groupId);

                if ($group === null || ! $group->isActive || ! ($scope->hasGroup($group->id) || $scope->hasRoom($roomId))) {
                    continue;
                }

                if ($session->kind !== SessionKind::Practica || ! ($state->subject($group->subjectId)?->needsLab ?? false)) {
                    continue;
                }

                yield Violation::hard(
                    $this->rule(),
                    "{$state->labelOf($group->id)} necesita laboratorio, taller o sala de cómputo para su práctica del {$session->describe()}, y el salón {$room->code} es de tipo ".mb_strtolower($room->type->label()).'.',
                    'room',
                    $roomId,
                    $group->id,
                    [
                        'room_id' => $roomId,
                        'session_id' => $session->id,
                        'room_type' => $room->type->value,
                    ],
                );
            }
        }
    }
}
