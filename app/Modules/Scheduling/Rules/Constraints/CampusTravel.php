<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * SC-09: si un docente tiene dos sesiones seguidas el mismo día en campus distintos y entre el fin de una y el inicio
 * de la otra quedan menos minutos que el mínimo de traslado (`staffing.min_campus_travel_minutes`, 60 sin
 * configurar), es un aviso: no bloquea (confirmado el 7 de noviembre de 2026). Seguidas quiere decir sin otra sesión
 * del docente en medio; dos que se empalman ya son HC-01. Cada aviso se reporta en los dos grupos, como HC-01.
 */
final class CampusTravel implements Constraint
{
    public const int DEFAULT_MINUTES = 60;

    public function rule(): string
    {
        return 'SC-09';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        $minimum = (int) $state->setting('staffing.min_campus_travel_minutes', self::DEFAULT_MINUTES);

        foreach ($scope->teacherIds($state) as $teacherId) {
            $name = $state->teacher($teacherId)?->name ?? 'El docente';

            foreach ($this->byDay($state->teacherSessions($teacherId)) as $sessions) {
                for ($i = 0, $last = count($sessions) - 1; $i < $last; $i++) {
                    [$first, $next] = [$sessions[$i], $sessions[$i + 1]];
                    $free = $next->start - $first->end;
                    $from = $state->groupOrFail($first->groupId)->campusId;
                    $to = $state->groupOrFail($next->groupId)->campusId;

                    if ($free < 0 || $free >= $minimum || $from === $to || ! $scope->touches($first->groupId, $next->groupId, $teacherId)) {
                        continue;
                    }

                    $trip = "{$name} tiene {$free} min para ir de {$state->campusCode($from)} (".Minutes::label($first->end).") a {$state->campusCode($to)} (".Minutes::label($next->start).') el '.Minutes::dayName($first->day);
                    $related = ['teacher_id' => $teacherId, 'day' => $first->day, 'free_minutes' => $free, 'min_minutes' => $minimum];

                    yield $this->warning("{$trip}, entre este grupo y {$state->labelOf($next->groupId)}; el mínimo es {$minimum} min.", $teacherId, $first, $next, $from, $to, $related);
                    yield $this->warning("{$trip}, entre {$state->labelOf($first->groupId)}, y este grupo; el mínimo es {$minimum} min.", $teacherId, $next, $first, $to, $from, $related);
                }
            }
        }
    }

    /**
     * Las sesiones del docente por día, en orden de inicio.
     *
     * @param  list<StateSession>  $sessions
     * @return array<int, list<StateSession>>
     */
    private function byDay(array $sessions): array
    {
        $days = [];

        foreach ($sessions as $session) {
            $days[$session->day][] = $session;
        }

        foreach ($days as &$day) {
            usort($day, fn (StateSession $a, StateSession $b): int => [$a->start, $a->end] <=> [$b->start, $b->end]);
        }

        return $days;
    }

    /**
     * @param  array{teacher_id: string, day: int, free_minutes: int, min_minutes: int}  $related
     */
    private function warning(string $message, string $teacherId, StateSession $mine, StateSession $other, string $campusId, string $otherCampusId, array $related): Violation
    {
        return Violation::soft($this->rule(), $message, 'teacher', $teacherId, $mine->groupId, [
            'teacher_id' => $teacherId,
            'session_id' => $mine->id,
            'other_session_id' => $other->id,
            'other_group_id' => $other->groupId,
            'campus_id' => $campusId,
            'other_campus_id' => $otherCampusId,
            'day' => $related['day'],
            'free_minutes' => $related['free_minutes'],
            'min_minutes' => $related['min_minutes'],
        ]);
    }
}
