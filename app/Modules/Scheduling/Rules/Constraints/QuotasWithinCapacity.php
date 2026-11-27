<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-07: la suma de `max_seats` de los cupos por carrera de un grupo no puede exceder su capacidad.
 */
final class QuotasWithinCapacity implements Constraint
{
    public function rule(): string
    {
        return 'HC-07';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);

            if ($group === null || ! $group->isActive) {
                continue;
            }

            $seats = array_sum(array_map(fn ($quota): int => $quota->maxSeats, $state->quotasOf($groupId)));

            if ($seats <= $group->capacity) {
                continue;
            }

            yield Violation::hard(
                $this->rule(),
                "Los cupos de {$state->labelOf($groupId)} suman {$seats} lugares y la capacidad es {$group->capacity}.",
                'group',
                $groupId,
                $groupId,
                ['seats' => $seats, 'capacity' => $group->capacity],
            );
        }
    }
}
