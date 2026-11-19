<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Scheduling\Rules\Cohorts;
use App\Modules\Scheduling\Rules\Timeline;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SessionSlot;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\TimeSlot;

/**
 * El puntaje blando incremental de la búsqueda local (tarea 6.7; docs/arquitectura/solver.md, «Heurística PHP: búsqueda
 * local»). Da el mismo puntaje que el evaluador en memoria (ContractEvaluator, con SoftScorer y los pesos de la entrada),
 * pero cuenta por pedazos para que el delta de un movimiento sea barato: cada regla blanda se parte en términos que
 * solo dependen de un docente en un día (SC-05, SC-06 y SC-09), de una cohorte en un día (SC-01), de un grupo (SC-02,
 * SC-03, SC-04 y SC-08) o de un par de grupos de semestres seguidos (SC-07). Un movimiento cambia las sesiones de uno o
 * dos grupos, así que el delta solo vuelve a contar los términos de sus docentes y cohortes en los días que tenían y
 * los que tendrían, los de esos grupos y sus pares; los demás quedan guardados.
 *
 * Cada término cuenta como su regla de Scheduling, con las mismas cuentas de Timeline y las unidades de
 * reglas-de-negocio.md, «Unidades del puntaje blando»:
 *
 * - SC-01: las horas de hueco entre las sesiones de una cohorte (clave de la entrada de algún grupo movible) en un día,
 *   donde una de las dos sesiones es de un grupo movible.
 * - SC-05, SC-06 y SC-09: con las sesiones del docente en el día en todos los campus (sus grupos movibles, sus grupos
 *   fijos y su `busy`, que entra con el campus de su primer grupo si no trae uno, como en ContractState), los huecos,
 *   los cambios de edificio entre sesiones pegadas y los pares seguidos en campus distintos sin el traslado, cuando una
 *   de las dos sesiones es de un grupo movible.
 * - SC-02, SC-03, SC-04 y SC-08: las horas de más en un día, fuera del salón por defecto y fuera de los preferidos del
 *   docente (más SC-08 si es de honorarios), de cada grupo movible.
 * - SC-07: las horas de empalme de cada par de grupos de cohortes de plan de semestres seguidos.
 *
 * Las sesiones iguales de un día se ordenan como en la foto del evaluador: por inicio, fin y el orden de la entrada (los
 * `busy` de los docentes después de los grupos). Una regla con peso cero no se cuenta. Vive lo que dura una búsqueda.
 */
final class LocalScore
{
    /** @var array<string, int> */
    private array $weights;

    private int $travel;

    /** @var array<string, true> los grupos movibles de la entrada (el alcance del evaluador) */
    private array $movable = [];

    /** @var array<string, string|null> por grupo, también los `busy` de cada docente */
    private array $teacherOf = [];

    /** @var array<string, string> por grupo, también los `busy` de cada docente */
    private array $campusOf = [];

    /** @var array<string, int> el orden de cada grupo en la foto del evaluador */
    private array $rank = [];

    /** @var array<string, list<string>> por grupo, sus claves de cohorte de SC-01 (sin `combination:`, Cohorts::forStudentGaps()) */
    private array $keysOf = [];

    /** @var array<string, string> por salón */
    private array $buildings = [];

    /** @var array<string, list<array{int, int, int}>> por docente, sus preferidos [día, inicio, fin] */
    private array $preferred = [];

    /** @var array<string, bool> */
    private array $honorarios = [];

    /** @var array<string, array<string, int>> por grupo movible, sus salones por defecto */
    private array $defaults = [];

    /** @var array<string, int> por grupo movible, el reparto parejo de SC-02 (horas por día) */
    private array $share = [];

    /** @var array<string, list<string>> por grupo movible, los grupos de las cohortes de plan de los semestres de al lado */
    private array $neighbours = [];

    /** @var array<string, array<int, list<StateSession>>> por grupo, sus sesiones por día */
    private array $byDay = [];

    /** @var array<string, array<int, array<string, true>>> docente → día → grupos con sesión */
    private array $teacherDays = [];

    /** @var array<string, array<int, array<string, true>>> cohorte → día → grupos con sesión */
    private array $cohortDays = [];

    /** @var array<string, int> el valor guardado de cada término, por su llave */
    private array $terms = [];

    /**
     * @param  array<string, list<ProposedSession>>  $sessions  las sesiones de los grupos movibles colocados
     */
    public function __construct(SolverInput $input, array $sessions)
    {
        $this->weights = $input->softWeights->weights;
        $this->travel = $input->travelMinutes;
        $members = [];
        $teacherCampus = [];
        $days = [];

        foreach ($input->campuses as $campus) {
            $days[$campus->code] = count(array_unique($campus->days));
        }

        foreach ($input->rooms as $room) {
            $this->buildings[$room->id] = $room->buildingId;
        }

        $defaults = [];

        foreach ($input->subjectRoomDefaults as $default) {
            $defaults["{$default->subjectCode}|{$default->campusCode}"][$default->roomId] = $default->priority;
        }

        foreach ($input->groups as $index => $group) {
            $this->rank[$group->id] = $index;
            $this->teacherOf[$group->id] = $group->teacherId;
            $this->campusOf[$group->id] = $group->campusCode;
            $this->keysOf[$group->id] = array_values(array_unique(Cohorts::forStudentGaps($group->cohorts)));

            foreach ($this->keysOf[$group->id] as $key) {
                $members[$key][] = $group->id;
            }

            if ($group->teacherId !== null) {
                $teacherCampus[$group->teacherId] ??= $group->campusCode;
            }

            if (! $group->movable) {
                $this->put($group->id, array_map(fn (SessionSlot $slot): StateSession => self::state($group->id, $slot->day, $slot->startsAt, $slot->endsAt, $slot->roomId), $group->fixedSessions));

                continue;
            }

            $weekly = max(0, $group->weeklyHours - $group->practiceHours) + $group->practiceHours;
            $campusDays = max(1, $days[$group->campusCode] ?? 1);
            $this->movable[$group->id] = true;
            $this->share[$group->id] = max(1, intdiv($weekly + $campusDays - 1, $campusDays));
            $this->defaults[$group->id] = $defaults["{$group->subjectCode}|{$group->campusCode}"] ?? [];
        }

        foreach (array_keys($this->movable) as $groupId) {
            $neighbours = [];

            foreach ($this->keysOf[$groupId] as $key) {
                $parts = explode(':', $key);

                if ($parts[0] === 'plan' && count($parts) === 4) {
                    foreach ([(int) $parts[2] - 1, (int) $parts[2] + 1] as $next) {
                        foreach ($members["plan:{$parts[1]}:{$next}:{$parts[3]}"] ?? [] as $other) {
                            $neighbours[$other] = true;
                        }
                    }
                }
            }

            unset($neighbours[$groupId]);
            $this->neighbours[$groupId] = array_map('strval', array_keys($neighbours));
        }

        $context = count($input->groups);

        foreach ($input->teachers as $teacher) {
            $this->preferred[$teacher->id] = array_map(fn (TimeSlot $slot): array => [$slot->day, Minutes::of($slot->startsAt), Minutes::of($slot->endsAt)], $teacher->preferred);
            $this->honorarios[$teacher->id] = $teacher->honorarios;
            $byCampus = [];

            foreach ($teacher->busy as $slot) {
                $byCampus[$slot->campusCode ?? $teacherCampus[$teacher->id] ?? $input->campuses[0]->code ?? ''][] = $slot;
            }

            foreach ($byCampus as $campus => $slots) {
                $id = "busy:teacher:{$teacher->id}:{$campus}";
                $this->rank[$id] = $context++;
                $this->teacherOf[$id] = $teacher->id;
                $this->campusOf[$id] = (string) $campus;
                $this->keysOf[$id] = [];
                $this->put($id, array_map(fn (TimeSlot $slot): StateSession => self::state($id, $slot->day, $slot->startsAt, $slot->endsAt, null), $slots));
            }
        }

        foreach ($sessions as $groupId => $proposed) {
            $this->put((string) $groupId, self::states($proposed));
        }
    }

    /**
     * El puntaje blando de todas las sesiones, como el total del evaluador.
     */
    public function total(): int
    {
        $total = 0;
        $teachers = [];
        $keys = [];

        foreach (array_keys($this->movable) as $groupId) {
            $groupId = (string) $groupId;
            $teachers[$this->teacherOf[$groupId] ?? ''] = true;

            foreach ($this->keysOf[$groupId] as $key) {
                $keys[$key] = true;
            }

            $total += $this->term("g|{$groupId}", ['g', $groupId], []);

            foreach ($this->neighbours[$groupId] as $other) {
                [$a, $b] = strcmp($groupId, $other) < 0 ? [$groupId, $other] : [$other, $groupId];
                $total += $a === $groupId || ! isset($this->movable[$other]) ? $this->term("p|{$a}|{$b}", ['p', $a, $b], []) : 0;
            }
        }

        unset($teachers['']);

        foreach (array_keys($teachers) as $teacherId) {
            foreach (array_keys($this->teacherDays[$teacherId] ?? []) as $day) {
                $total += $this->term("t|{$teacherId}|{$day}", ['t', (string) $teacherId, $day], []);
            }
        }

        foreach (array_keys($keys) as $key) {
            foreach (array_keys($this->cohortDays[$key] ?? []) as $day) {
                $total += $this->term("c|{$key}|{$day}", ['c', (string) $key, $day], []);
            }
        }

        return $total;
    }

    /**
     * Cuánto cambiaría el puntaje si esos grupos movibles tuvieran esas sesiones (negativo: mejora), sin cambiar nada.
     *
     * @param  array<string, list<ProposedSession>>  $changes
     */
    public function delta(array $changes): int
    {
        $changed = array_map(fn (array $sessions): array => self::byDayOf(self::states($sessions)), $changes);
        $delta = 0;

        foreach ($this->affected($changed) as $key => $term) {
            $delta += $this->cost($term, $changed) - $this->term($key, $term, []);
        }

        return $delta;
    }

    /**
     * Cambia las sesiones de esos grupos movibles.
     *
     * @param  array<string, list<ProposedSession>>  $changes
     */
    public function apply(array $changes): void
    {
        $changed = array_map(fn (array $sessions): array => self::byDayOf(self::states($sessions)), $changes);

        foreach (array_keys($this->affected($changed)) as $key) {
            unset($this->terms[$key]);
        }

        foreach ($changes as $groupId => $sessions) {
            $this->put((string) $groupId, self::states($sessions));
        }
    }

    /**
     * Los términos que tocan esos grupos: sus docentes y cohortes en los días de antes y de después, ellos y sus pares.
     *
     * @param  array<string, array<int, list<StateSession>>>  $changed
     * @return array<string, array{0: string, 1: string, 2?: int|string}>
     */
    private function affected(array $changed): array
    {
        $terms = [];

        foreach ($changed as $groupId => $byDay) {
            $groupId = (string) $groupId;
            $teacherId = $this->teacherOf[$groupId] ?? null;

            foreach (array_unique([...array_keys($this->byDay[$groupId] ?? []), ...array_keys($byDay)]) as $day) {
                if ($teacherId !== null) {
                    $terms["t|{$teacherId}|{$day}"] = ['t', $teacherId, $day];
                }

                foreach ($this->keysOf[$groupId] as $key) {
                    $terms["c|{$key}|{$day}"] = ['c', $key, $day];
                }
            }

            $terms["g|{$groupId}"] = ['g', $groupId];

            foreach ($this->neighbours[$groupId] ?? [] as $other) {
                [$a, $b] = strcmp($groupId, $other) < 0 ? [$groupId, $other] : [$other, $groupId];
                $terms["p|{$a}|{$b}"] = ['p', $a, $b];
            }
        }

        return $terms;
    }

    /**
     * El valor guardado de un término sin cambios, o lo calcula y lo guarda.
     *
     * @param  array{0: string, 1: string, 2?: int|string}  $term
     * @param  array<string, array<int, list<StateSession>>>  $changed
     */
    private function term(string $key, array $term, array $changed): int
    {
        return $this->terms[$key] ??= $this->cost($term, $changed);
    }

    /**
     * @param  array{0: string, 1: string, 2?: int|string}  $term
     * @param  array<string, array<int, list<StateSession>>>  $changed
     */
    private function cost(array $term, array $changed): int
    {
        return match ($term[0]) {
            't' => $this->teacherDay($term[1], (int) $term[2], $changed),
            'c' => $this->cohortDay($term[1], (int) $term[2], $changed),
            'g' => $this->group($term[1], $changed),
            default => $this->pair($term[1], (string) $term[2], $changed),
        };
    }

    /**
     * SC-05, SC-06 y SC-09 de un docente en un día.
     *
     * @param  array<string, array<int, list<StateSession>>>  $changed
     */
    private function teacherDay(string $teacherId, int $day, array $changed): int
    {
        $groups = $this->teacherDays[$teacherId][$day] ?? [];

        foreach ($changed as $groupId => $byDay) {
            if (($this->teacherOf[$groupId] ?? null) === $teacherId && isset($byDay[$day])) {
                $groups[$groupId] = true;
            }
        }

        $sessions = $this->sorted($groups, $day, $changed);
        $cost = 0;

        if ($this->weights['SC-05'] > 0) {
            foreach (Timeline::gaps($sessions) as [$before, $after, $minutes]) {
                if (isset($this->movable[$before->groupId]) || isset($this->movable[$after->groupId])) {
                    $cost += $this->weights['SC-05'] * Timeline::hours($minutes);
                }
            }
        }

        for ($i = 0, $last = count($sessions) - 1; $i < $last && ($this->weights['SC-06'] > 0 || $this->weights['SC-09'] > 0); $i++) {
            [$before, $after] = [$sessions[$i], $sessions[$i + 1]];

            if (! isset($this->movable[$before->groupId]) && ! isset($this->movable[$after->groupId])) {
                continue;
            }

            $from = $before->roomId === null ? null : $this->buildings[$before->roomId] ?? null;
            $to = $after->roomId === null ? null : $this->buildings[$after->roomId] ?? null;

            if ($after->start === $before->end && $from !== null && $to !== null && $from !== $to) {
                $cost += $this->weights['SC-06'];
            }

            $free = $after->start - $before->end;

            if ($free >= 0 && $free < $this->travel && $this->campusOf[$before->groupId] !== $this->campusOf[$after->groupId]) {
                $cost += $this->weights['SC-09'];
            }
        }

        return $cost;
    }

    /**
     * SC-01 de una cohorte en un día.
     *
     * @param  array<string, array<int, list<StateSession>>>  $changed
     */
    private function cohortDay(string $key, int $day, array $changed): int
    {
        if ($this->weights['SC-01'] === 0) {
            return 0;
        }

        $groups = $this->cohortDays[$key][$day] ?? [];

        foreach ($changed as $groupId => $byDay) {
            if (isset($byDay[$day]) && in_array($key, $this->keysOf[$groupId], true)) {
                $groups[$groupId] = true;
            }
        }

        $hours = 0;

        foreach (Timeline::gaps($this->sorted($groups, $day, $changed)) as [$before, $after, $minutes]) {
            if (isset($this->movable[$before->groupId]) || isset($this->movable[$after->groupId])) {
                $hours += Timeline::hours($minutes);
            }
        }

        return $this->weights['SC-01'] * $hours;
    }

    /**
     * SC-02, SC-03, SC-04 y SC-08 de un grupo movible.
     *
     * @param  array<string, array<int, list<StateSession>>>  $changed
     */
    private function group(string $groupId, array $changed): int
    {
        $byDay = $changed[$groupId] ?? $this->byDay[$groupId] ?? [];
        $teacherId = $this->teacherOf[$groupId];
        $blocks = $teacherId === null ? [] : $this->preferred[$teacherId] ?? [];
        $honorarios = $teacherId !== null && ($this->honorarios[$teacherId] ?? false);
        $cost = 0;

        foreach ($byDay as $sessions) {
            $minutes = 0;

            foreach ($sessions as $session) {
                $minutes += $session->minutes();

                if ($this->defaults[$groupId] !== [] && $session->roomId !== null && ! isset($this->defaults[$groupId][$session->roomId])) {
                    $cost += $this->weights['SC-03'] * Timeline::hours($session->minutes());
                }

                if ($blocks !== []) {
                    $outside = Timeline::hours(Timeline::uncovered($session, $blocks));
                    $cost += $this->weights['SC-04'] * $outside + ($honorarios ? $this->weights['SC-08'] * $outside : 0);
                }
            }

            $cost += $this->weights['SC-02'] * max(0, Timeline::hours($minutes) - $this->share[$groupId]);
        }

        return $cost;
    }

    /**
     * SC-07 de un par de grupos.
     *
     * @param  array<string, array<int, list<StateSession>>>  $changed
     */
    private function pair(string $a, string $b, array $changed): int
    {
        if ($this->weights['SC-07'] === 0) {
            return 0;
        }

        $theirs = $changed[$b] ?? $this->byDay[$b] ?? [];
        $minutes = 0;

        foreach ($changed[$a] ?? $this->byDay[$a] ?? [] as $day => $sessions) {
            foreach ($sessions as $mine) {
                foreach ($theirs[$day] ?? [] as $other) {
                    $minutes += Timeline::overlap($mine, $other);
                }
            }
        }

        return $this->weights['SC-07'] * Timeline::hours($minutes);
    }

    /**
     * Las sesiones de esos grupos en el día, en el orden de la foto del evaluador.
     *
     * @param  array<string, true>  $groups
     * @param  array<string, array<int, list<StateSession>>>  $changed
     * @return list<StateSession>
     */
    private function sorted(array $groups, int $day, array $changed): array
    {
        $sessions = [];

        foreach (array_keys($groups) as $groupId) {
            $groupId = (string) $groupId;

            foreach (isset($changed[$groupId]) ? $changed[$groupId][$day] ?? [] : $this->byDay[$groupId][$day] ?? [] as $session) {
                $sessions[] = $session;
            }
        }

        usort($sessions, fn (StateSession $a, StateSession $b): int => [$a->start, $a->end, $this->rank[$a->groupId]] <=> [$b->start, $b->end, $this->rank[$b->groupId]]);

        return $sessions;
    }

    /**
     * Cambia las sesiones de un grupo en los índices por docente y cohorte.
     *
     * @param  list<StateSession>  $sessions
     */
    private function put(string $groupId, array $sessions): void
    {
        $teacherId = $this->teacherOf[$groupId] ?? null;

        foreach (array_keys($this->byDay[$groupId] ?? []) as $day) {
            if ($teacherId !== null) {
                unset($this->teacherDays[$teacherId][$day][$groupId]);
            }

            foreach ($this->keysOf[$groupId] ?? [] as $key) {
                unset($this->cohortDays[$key][$day][$groupId]);
            }
        }

        $this->byDay[$groupId] = self::byDayOf($sessions);

        foreach (array_keys($this->byDay[$groupId]) as $day) {
            if ($teacherId !== null) {
                $this->teacherDays[$teacherId][$day][$groupId] = true;
            }

            foreach ($this->keysOf[$groupId] ?? [] as $key) {
                $this->cohortDays[$key][$day][$groupId] = true;
            }
        }
    }

    /**
     * @param  list<StateSession>  $sessions
     * @return array<int, list<StateSession>>
     */
    private static function byDayOf(array $sessions): array
    {
        $byDay = [];

        foreach ($sessions as $session) {
            $byDay[$session->day][] = $session;
        }

        return $byDay;
    }

    /**
     * @param  list<ProposedSession>  $sessions
     * @return list<StateSession>
     */
    private static function states(array $sessions): array
    {
        return array_map(fn (ProposedSession $session): StateSession => self::state($session->groupId, $session->day, $session->startsAt, $session->endsAt, $session->roomId), $sessions);
    }

    private static function state(string $groupId, int $day, string $startsAt, string $endsAt, ?string $roomId): StateSession
    {
        return new StateSession(null, $groupId, $day, Minutes::of($startsAt), Minutes::of($endsAt), $roomId);
    }
}
