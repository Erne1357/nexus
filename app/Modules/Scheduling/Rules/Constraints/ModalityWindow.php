<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-04: las sesiones deben caer dentro de la ventana de la modalidad del grupo (`day_start`, `day_end`, `days` de su
 * perfil) y alinearse a `block_minutes`. La alineación se cuenta desde `day_start`. Una modalidad sin bloques de tiempo
 * (`uses_time_blocks` en falso) no tiene ventana y no se revisa. El campus es solo el lugar: su perfil no cuenta.
 */
final class ModalityWindow implements Constraint
{
    public function rule(): string
    {
        return 'HC-04';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);
            $profile = $group === null ? null : $state->profile($group->modalityId);

            if ($group === null || ! $group->isActive || $profile === null || ! $profile->usesTimeBlocks) {
                continue;
            }

            $dayStart = Minutes::of($profile->dayStart);
            $dayEnd = Minutes::of($profile->dayEnd);

            foreach ($state->sessionsOf($groupId) as $session) {
                if (! $this->isOutside($session, $profile->days, $dayStart, $dayEnd, $profile->blockMinutes)) {
                    continue;
                }

                yield Violation::hard(
                    $this->rule(),
                    "La sesión del {$session->describe()} de {$state->labelOf($groupId)} cae fuera de la ventana de la modalidad ({$profile->schedule()}).",
                    'group',
                    $groupId,
                    $groupId,
                    [
                        'session_id' => $session->id,
                        'day' => $session->day,
                        'starts_at' => Minutes::format($session->start),
                        'ends_at' => Minutes::format($session->end),
                        'window' => $profile->schedule(),
                        'block_minutes' => $profile->blockMinutes,
                    ],
                );
            }
        }
    }

    /**
     * @param  list<int>  $days
     */
    private function isOutside(StateSession $session, array $days, int $dayStart, int $dayEnd, int $blockMinutes): bool
    {
        return ! in_array($session->day, $days, true)
            || $session->start < $dayStart
            || $session->end > $dayEnd
            || ($session->start - $dayStart) % $blockMinutes !== 0
            || ($session->end - $dayStart) % $blockMinutes !== 0;
    }
}
