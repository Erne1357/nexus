<?php

namespace App\Modules\Solver\Rules;

use App\Modules\Core\Support\WeekTime;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;

/**
 * Qué escribe aceptar una corrida (SV-05, SV-06; tarea 6.10), a partir de su entrada y su salida. Lo usan
 * ApplySolverRun, el resumen de «Aceptar» y la comparación de la pantalla, así los tres dicen lo mismo. Puro: no lee la
 * base.
 *
 * Por cada grupo movible de la entrada, con sus sesiones de hoy (`current_sessions`) y las propuestas, unidas las que se
 * pegan con el mismo tipo y salón (ReplaceGroupSessions::joined, como las guarda el editor):
 * - `write`: lo propuesto es distinto de lo de hoy y se escribe;
 * - `same`: lo propuesto es igual a lo de hoy y no se toca;
 * - `kept`: se queda como está, con sus motivos. Son los que el motor dejó sin acomodar (`unscheduled`, con sus motivos),
 *   los de una violación dura de acomodo (`hard_violations[].group_ids` que no son de INPUT_RULES, con la regla y
 *   el mensaje; las de INPUT_RULES son `inputProblems` y no retienen), los que no recibieron ninguna
 *   sesión y los que chocarían con lo que se queda (supuesto por confirmar): si las sesiones nuevas de un grupo se
 *   empalman con las de su docente (HC-01) o de su salón (HC-02) en lo que no se mueve (las sesiones fijas de la
 *   entrada, la ocupación de docentes y salones fuera de la corrida y las de hoy de los grupos que se quedan) o con las
 *   nuevas de un grupo anterior (en el orden de la entrada), ese grupo también se queda. Como quedarse devuelve sus
 *   sesiones de hoy, se repite hasta que nada cambia.
 */
final readonly class ApplyPlan
{
    public const string WRITE = 'write';

    public const string SAME = 'same';

    public const string KEPT = 'kept';

    /**
     * Las reglas duras que NO dependen de dónde quedan las sesiones: HC-07 (cupos), HC-08 (horas del docente) y HC-13
     * (grupo ofertable). No retienen al grupo: se escriben sus sesiones y la violación se reporta como `inputProblems`
     * por corregir. Todo lo demás retiene (HC-01 a HC-06 y HC-09 a HC-12 dependen del acomodo) y también una regla
     * desconocida, que es lo seguro: no se escribe una sesión con un choque que no conocemos (supuesto por confirmar;
     * acordado entre rieles el 18 de noviembre de 2026).
     */
    public const array INPUT_RULES = ['HC-07', 'HC-08', 'HC-13'];

    /**
     * @param  array<string, array{status: string, current: list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>, proposed: list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>, reasons: list<string>}>  $groups
     * @param  list<array{rule: string, group_ids: list<string>, message: string}>  $inputProblems  las violaciones que no retienen
     */
    private function __construct(public array $groups, public array $inputProblems = []) {}

    public static function of(SolverInput $input, SolverOutput $output): self
    {
        $proposed = [];

        foreach ($output->sessions as $session) {
            $proposed[$session->groupId][] = self::proposedSlot($session);
        }

        $excluded = [];

        foreach ($output->unscheduled as $unscheduled) {
            $excluded[$unscheduled->groupId] = [...($excluded[$unscheduled->groupId] ?? []), ...($unscheduled->reasons === [] ? ['El motor no lo pudo acomodar.'] : $unscheduled->reasons)];
        }

        $inputProblems = [];

        foreach ($output->hardViolations as $violation) {
            if (in_array($violation->rule, self::INPUT_RULES, true)) {
                $inputProblems[] = ['rule' => $violation->rule, 'group_ids' => array_values($violation->groupIds), 'message' => $violation->message];

                continue;
            }

            foreach ($violation->groupIds as $groupId) {
                $excluded[$groupId][] = "{$violation->rule}: {$violation->message}";
            }
        }

        $groups = [];

        foreach ($input->groups as $group) {
            if (! $group->movable) {
                continue;
            }

            $current = ReplaceGroupSessions::joined(array_map(self::slot(...), $group->currentSessions));
            $new = ReplaceGroupSessions::joined($proposed[$group->id] ?? []);
            $reasons = $excluded[$group->id] ?? ($new === [] ? ['El motor no le propuso sesiones.'] : []);

            $groups[$group->id] = [
                'status' => $reasons !== [] ? self::KEPT : (self::same($current, $new) ? self::SAME : self::WRITE),
                'current' => $current,
                'proposed' => $new,
                'reasons' => $reasons,
            ];
        }

        return new self(self::withoutClashes($input, $groups), $inputProblems);
    }

    /**
     * Las sesiones nuevas de los grupos que se escriben, por id del grupo.
     *
     * @return array<string, list<array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}>>
     */
    public function writes(): array
    {
        return array_map(fn (array $group): array => $group['proposed'], array_filter($this->groups, fn (array $group): bool => $group['status'] === self::WRITE));
    }

    /**
     * Los grupos que se quedan como están, con sus motivos, por id del grupo.
     *
     * @return array<string, list<string>>
     */
    public function kept(): array
    {
        return array_map(fn (array $group): array => $group['reasons'], array_filter($this->groups, fn (array $group): bool => $group['status'] === self::KEPT));
    }

    public function status(string $groupId): ?string
    {
        return $this->groups[$groupId]['status'] ?? null;
    }

    /**
     * @param  array<string, array{status: string, current: list<array<string, mixed>>, proposed: list<array<string, mixed>>, reasons: list<string>}>  $groups
     * @return array<string, array{status: string, current: list<array<string, mixed>>, proposed: list<array<string, mixed>>, reasons: list<string>}>
     */
    private static function withoutClashes(SolverInput $input, array $groups): array
    {
        $teacherOf = [];

        foreach ($input->groups as $group) {
            $teacherOf[$group->id] = $group->teacherId;
        }

        do {
            $teachers = [];
            $rooms = [];
            $add = function (?string $teacherId, ?string $roomId, int $day, string $startsAt, string $endsAt) use (&$teachers, &$rooms): void {
                $range = [WeekTime::minutes($startsAt), WeekTime::minutes($endsAt), $day, $startsAt, $endsAt];

                if ($teacherId !== null) {
                    $teachers[$teacherId][$day][] = $range;
                }

                if ($roomId !== null) {
                    $rooms[$roomId][$day][] = $range;
                }
            };

            foreach ($input->teachers as $teacher) {
                foreach ($teacher->busy as $slot) {
                    $add($teacher->id, null, $slot->day, $slot->startsAt, $slot->endsAt);
                }
            }

            foreach ($input->rooms as $room) {
                foreach ($room->busy as $slot) {
                    $add(null, $room->id, $slot->day, $slot->startsAt, $slot->endsAt);
                }
            }

            foreach ($input->groups as $group) {
                $sessions = $group->movable ? (($groups[$group->id]['status'] ?? null) === self::WRITE ? [] : $groups[$group->id]['current']) : array_map(self::slot(...), $group->fixedSessions);

                foreach ($sessions as $session) {
                    $add($group->teacherId, $session['room_id'], $session['day'], $session['starts_at'], $session['ends_at']);
                }
            }

            $changed = false;

            foreach ($groups as $groupId => $group) {
                if ($group['status'] !== self::WRITE) {
                    continue;
                }

                $clash = self::clash($group['proposed'], $teacherOf[$groupId], $teachers, $rooms);

                if ($clash !== null) {
                    $groups[$groupId]['status'] = self::KEPT;
                    $groups[$groupId]['reasons'] = [$clash];
                    $changed = true;

                    continue;
                }

                foreach ($group['proposed'] as $session) {
                    $add($teacherOf[$groupId], $session['room_id'], $session['day'], $session['starts_at'], $session['ends_at']);
                }
            }
        } while ($changed);

        return $groups;
    }

    /**
     * El motivo del primer empalme de las sesiones nuevas con lo ocupado, o null.
     *
     * @param  list<array{day: int, starts_at: string, ends_at: string, room_id: ?string}>  $sessions
     * @param  array<string, array<int, list<array{0: int, 1: int, 2: int, 3: string, 4: string}>>>  $teachers
     * @param  array<string, array<int, list<array{0: int, 1: int, 2: int, 3: string, 4: string}>>>  $rooms
     */
    private static function clash(array $sessions, ?string $teacherId, array $teachers, array $rooms): ?string
    {
        foreach ($sessions as $session) {
            $start = WeekTime::minutes($session['starts_at']);
            $end = WeekTime::minutes($session['ends_at']);
            $when = WeekTime::day($session['day']).' de '.WeekTime::clock($session['starts_at']).' a '.WeekTime::clock($session['ends_at']);

            foreach ($teacherId === null ? [] : ($teachers[$teacherId][$session['day']] ?? []) as [$from, $to]) {
                if ($start < $to && $from < $end) {
                    return "HC-01: su docente ya tiene clase el {$when}; se queda como está.";
                }
            }

            foreach ($session['room_id'] === null ? [] : ($rooms[$session['room_id']][$session['day']] ?? []) as [$from, $to]) {
                if ($start < $to && $from < $end) {
                    return "HC-02: su salón ya está ocupado el {$when}; se queda como está.";
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $new
     */
    private static function same(array $current, array $new): bool
    {
        $key = fn (array $slot): string => implode('|', [$slot['day'], $slot['starts_at'], $slot['ends_at'], $slot['kind'], $slot['room_id'] ?? '']);
        $a = array_map($key, $current);
        $b = array_map($key, $new);
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * @return array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}
     */
    public static function slot(SessionSlot $slot): array
    {
        return ['day' => $slot->day, 'starts_at' => $slot->startsAt, 'ends_at' => $slot->endsAt, 'kind' => $slot->kind->value, 'room_id' => $slot->roomId];
    }

    /**
     * @return array{day: int, starts_at: string, ends_at: string, kind: string, room_id: ?string}
     */
    private static function proposedSlot(ProposedSession $session): array
    {
        return ['day' => $session->day, 'starts_at' => $session->startsAt, 'ends_at' => $session->endsAt, 'kind' => $session->kind->value, 'room_id' => $session->roomId];
    }
}
