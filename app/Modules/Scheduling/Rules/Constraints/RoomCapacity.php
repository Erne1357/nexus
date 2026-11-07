<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-05: la capacidad del grupo no puede exceder la capacidad del salón de ninguna de sus sesiones. Revisa los
 * grupos del alcance y las sesiones de los salones que el alcance nombra.
 */
final class RoomCapacity implements Constraint
{
    public function rule(): string
    {
        return 'HC-05';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->roomIds($state) as $roomId) {
            $room = $state->room($roomId);

            if ($room === null) {
                continue;
            }

            foreach ($state->roomSessions($roomId) as $session) {
                $group = $state->group($session->groupId);

                if ($group === null || ! $group->isActive || ! ($scope->hasGroup($group->id) || $scope->hasRoom($roomId))) {
                    continue;
                }

                if ($room->capacity >= $group->capacity) {
                    continue;
                }

                yield Violation::hard(
                    $this->rule(),
                    "El salón {$room->code} tiene {$room->capacity} lugares y {$state->labelOf($group->id)} tiene {$group->capacity} ({$session->describe()}).",
                    'room',
                    $roomId,
                    $group->id,
                    [
                        'room_id' => $roomId,
                        'session_id' => $session->id,
                        'room_capacity' => $room->capacity,
                        'group_capacity' => $group->capacity,
                    ],
                );
            }
        }
    }
}
