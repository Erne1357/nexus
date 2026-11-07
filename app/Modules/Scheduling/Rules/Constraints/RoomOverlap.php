<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-02: un salón no puede tener dos sesiones que se empalmen en el mismo día y periodo. La base lo rechaza
 * (`group_sessions_room_no_overlap`); esta regla da el mensaje antes de escribir y revisa lo que se pregunta sin
 * guardar. Cada empalme se reporta en los dos grupos.
 */
final class RoomOverlap implements Constraint
{
    public function rule(): string
    {
        return 'HC-02';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->roomIds($state) as $roomId) {
            $sessions = $state->roomSessions($roomId);
            $count = count($sessions);
            $code = $state->room($roomId)?->code ?? 'El salón';

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$a, $b] = [$sessions[$i], $sessions[$j]];

                    if ($a->groupId === $b->groupId || ! $a->overlaps($b) || ! $scope->touches($a->groupId, $b->groupId, roomId: $roomId)) {
                        continue;
                    }

                    foreach ([[$a, $b], [$b, $a]] as [$mine, $other]) {
                        yield Violation::hard(
                            $this->rule(),
                            "El salón {$code} ya está ocupado por {$state->labelOf($other->groupId)} el {$other->describe()}.",
                            'room',
                            $roomId,
                            $mine->groupId,
                            [
                                'room_id' => $roomId,
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
    }
}
