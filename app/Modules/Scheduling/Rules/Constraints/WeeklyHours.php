<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-12: las horas semanales de sesiones de un grupo deben ser iguales a `theory_hours + practice_hours` de la
 * materia, con la tolerancia de la modalidad del grupo (MS17, supuesto por confirmar). La tolerancia, en minutos, es
 * la clave `weekly_hours_tolerance` del perfil de la modalidad; sin ella, la configuración global
 * `scheduling.weekly_hours_tolerance` (0 sin configurar). Un grupo sin sesiones no se revisa (el tablero lo cuenta como
 * «sin horario»), ni el de una modalidad sin bloques de tiempo.
 */
final class WeeklyHours implements Constraint
{
    public function rule(): string
    {
        return 'HC-12';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        $global = (int) $state->setting('scheduling.weekly_hours_tolerance', 0);

        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);
            $subject = $group === null ? null : $state->subject($group->subjectId);
            $sessions = $state->sessionsOf($groupId);
            $profile = $group === null ? null : $state->profile($group->modalityId);

            if ($group === null || $subject === null || ! $group->isActive || $sessions === [] || ! $profile?->usesTimeBlocks) {
                continue;
            }

            $tolerance = $profile->weeklyHoursTolerance ?? $global;

            $required = $subject->weeklyHours() * 60;
            $scheduled = array_sum(array_map(fn ($session): int => $session->minutes(), $sessions));

            if (abs($scheduled - $required) <= $tolerance) {
                continue;
            }

            $difference = abs($scheduled - $required);
            $verb = $scheduled < $required ? 'Faltan' : 'Sobran';

            if ($difference === 60) {
                $verb = $scheduled < $required ? 'Falta' : 'Sobra';
            }

            yield Violation::hard(
                $this->rule(),
                "{$verb} {$this->hours($difference)} de las {$this->hours($required)} semanales de {$state->labelOf($groupId)}: tiene {$this->hours($scheduled)}.",
                'group',
                $groupId,
                $groupId,
                [
                    'required_minutes' => $required,
                    'scheduled_minutes' => $scheduled,
                    'tolerance_minutes' => $tolerance,
                ],
            );
        }
    }

    private function hours(int $minutes): string
    {
        $hours = rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');

        return "{$hours} h";
    }
}
