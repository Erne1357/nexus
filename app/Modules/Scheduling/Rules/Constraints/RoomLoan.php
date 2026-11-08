<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateRoomLoan;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-10: un salón con `department_id` dueño solo puede usarse por otro departamento si existe un acuerdo de préstamo
 * `accepted` que cubra el día y la hora. Varios préstamos aceptados contiguos cubren juntos una sesión; uno
 * propuesto que toca la sesión da el aviso de préstamo pendiente.
 */
final class RoomLoan implements Constraint
{
    public function rule(): string
    {
        return 'HC-10';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);

            if ($group === null || ! $group->isActive) {
                continue;
            }

            foreach ($state->sessionsOf($groupId) as $session) {
                $room = $session->roomId === null ? null : $state->room($session->roomId);

                if ($room === null || $room->departmentId === null || $room->departmentId === $group->departmentId) {
                    continue;
                }

                $loans = array_values(array_filter(
                    $state->loansOf($groupId),
                    fn (StateRoomLoan $loan): bool => $loan->roomId === $room->id && $loan->day === $session->day,
                ));

                if ($this->covers(array_filter($loans, fn (StateRoomLoan $loan): bool => $loan->isAccepted), $session)) {
                    continue;
                }

                $pending = array_filter($loans, fn (StateRoomLoan $loan): bool => Minutes::overlap($loan->start, $loan->end, $session->start, $session->end)) !== [];
                $detail = $pending
                    ? "su préstamo para el {$session->describe()} sigue pendiente (préstamo pendiente)"
                    : "no hay préstamo para el {$session->describe()} (sin préstamo)";

                yield Violation::hard(
                    $this->rule(),
                    "El salón {$room->code} es de otro departamento y {$detail}.",
                    'room',
                    $room->id,
                    $groupId,
                    [
                        'room_id' => $room->id,
                        'owner_department_id' => $room->departmentId,
                        'loan' => $pending ? 'pending' : 'none',
                    ],
                );
            }
        }
    }

    /**
     * Si la unión de los préstamos cubre toda la sesión.
     *
     * @param  iterable<StateRoomLoan>  $loans
     */
    private function covers(iterable $loans, StateSession $session): bool
    {
        $sorted = [...$loans];
        usort($sorted, fn (StateRoomLoan $a, StateRoomLoan $b): int => $a->start <=> $b->start);

        $covered = $session->start;

        foreach ($sorted as $loan) {
            if ($loan->start > $covered) {
                break;
            }

            $covered = max($covered, $loan->end);
        }

        return $covered >= $session->end;
    }
}
