<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-11: un salón no puede usarse en bloques con `room_blocks` vigentes. Un bloqueo contiguo a la sesión no la
 * toca. Revisa los grupos del alcance y las sesiones de los salones que el alcance nombra.
 */
final class RoomBlocked implements Constraint
{
    public function rule(): string
    {
        return 'HC-11';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->roomIds($state) as $roomId) {
            $blocks = $state->blocksOf($roomId);

            if ($blocks === []) {
                continue;
            }

            $code = $state->room($roomId)?->code ?? $roomId;

            foreach ($state->roomSessions($roomId) as $session) {
                $group = $state->group($session->groupId);

                if ($group === null || ! $group->isActive || ! ($scope->hasGroup($group->id) || $scope->hasRoom($roomId))) {
                    continue;
                }

                foreach ($blocks as $block) {
                    if ($block->day !== $session->day || ! Minutes::overlap($session->start, $session->end, $block->start, $block->end)) {
                        continue;
                    }

                    $when = Minutes::dayName($block->day).' de '.Minutes::label($block->start).' a '.Minutes::label($block->end);
                    $reason = $block->reason === '' ? '' : " ({$block->reason})";

                    yield Violation::hard(
                        $this->rule(),
                        "El salón {$code} está bloqueado el {$when}{$reason} y {$state->labelOf($group->id)} lo usa el {$session->describe()}.",
                        'room',
                        $roomId,
                        $group->id,
                        [
                            'room_id' => $roomId,
                            'session_id' => $session->id,
                            'day' => $block->day,
                            'starts_at' => Minutes::format($block->start),
                            'ends_at' => Minutes::format($block->end),
                            'reason' => $block->reason,
                        ],
                    );
                }
            }
        }
    }
}
