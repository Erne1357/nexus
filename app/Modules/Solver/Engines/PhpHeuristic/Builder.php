<?php

namespace App\Modules\Solver\Engines\PhpHeuristic;

use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\ProposedSession;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SolverInput;
use Closure;

/**
 * La construcción voraz de la heurística en PHP (tarea 6.6; docs/arquitectura/solver.md, «Heurística PHP:
 * construcción»). Coloca los grupos movibles uno por uno, lo más restringido primero (GroupOrder), cada uno completo o
 * nada: en sesiones de una hora a la misma hora en los días de su patrón (SessionPattern) y un salón por día.
 *
 * La factibilidad se pregunta a índices de ocupación que se actualizan al colocar cada grupo, por bloque de la rejilla
 * de cada campus: docente × día × bloque (HC-01, con la ocupación de fuera de la corrida en todos los campus; HC-09 en
 * otro índice), salón × día × bloque (HC-02, con sus bloqueos aparte, HC-11), cohorte × día × bloque (HC-03) y los
 * préstamos aceptados por salón, departamento y día (HC-10). La capacidad (HC-05), el laboratorio (HC-06) y el dueño del
 * salón se filtran al ordenar los salones del grupo; la ventana (HC-04) y las horas exactas (HC-12) salen de cómo se
 * arman los candidatos. HC-08 no depende del horario: se revisa antes (docentes cuyos grupos movibles suman más de lo
 * que les queda) y se reporta. Solo se revisan las reglas activas de la entrada (`hard_rules`). Las cohortes son más
 * estrictas que HC-03: dos grupos con una misma clave nunca se empalman, como dice el contrato.
 *
 * Entre los candidatos que caben gana el de menor puntaje blando estimado con los mismos índices y los pesos de la
 * entrada: huecos del alumno tipo (SC-01) y del docente (SC-05), horas fuera de los preferidos (SC-04, y SC-08 con
 * honorarios), empalmes con el semestre de al lado (SC-07), salón por defecto (SC-03), cambio de edificio (SC-06) y
 * traslado entre campus (SC-09); SC-02 queda en cero por construcción. Empatan por la hora actual del grupo
 * (`current_sessions`), luego por el orden del patrón y la hora más temprana. La autoridad es el evaluador: el motor
 * valida al final (PhpHeuristicEngine).
 *
 * Los salones de cada grupo se ordenan: el por defecto de la materia (`subject_room_defaults`, por prioridad), el tipo
 * (aula para la teoría, laboratorio, taller o sala de cómputo para la práctica de una materia con laboratorio), la
 * capacidad más ajustada y el id. Al elegir, primero un mismo salón todos los días; si no, uno del mismo edificio; y
 * el salón actual del grupo si es igual de bueno.
 *
 * Vive lo que dura una corrida (Octane): el motor crea uno por llamada.
 */
final class Builder
{
    /** El dueño de una ocupación de fuera de la corrida (`busy` de docentes y salones). */
    private const string OUTSIDE = 'busy:';

    private const string THEORY = 'theory';

    private const string LAB = 'lab';

    /** @var array<string, Grid> por campus */
    private array $grids = [];

    /** @var array<string, true> */
    private array $checks;

    /** @var array<string, int> */
    private array $weights;

    /** @var array<string, GroupInput> */
    private array $groups = [];

    /** @var array<string, array{campus: string, type: RoomType, capacity: int, building: string, owner: ?string}> */
    private array $rooms = [];

    /** @var array<string, list<string>> */
    private array $roomsByCampus = [];

    /** @var array<string, array<string, array<int, array<int, string>>>> docente → campus → día → bloque → grupo */
    private array $teacherAt = [];

    /** @var array<string, array<string, array<int, array<int, true>>>> docente → campus → día → bloque (HC-09) */
    private array $teacherOff = [];

    /** @var array<string, array<string, array<int, array<int, true>>>> docente → campus → día → bloque preferido */
    private array $teacherPreferred = [];

    /** @var array<string, array<int, list<array{int, int, string, ?string}>>> docente → día → [inicio, fin, campus, edificio] */
    private array $teacherSpans = [];

    /** @var array<string, bool> */
    private array $honorarios = [];

    /** @var array<string, array<int, array<int, string>>> salón → día → bloque → grupo */
    private array $roomAt = [];

    /** @var array<string, array<int, array<int, true>>> salón → día → bloque (HC-11) */
    private array $roomBlocked = [];

    /** @var array<string, array<string, array<int, array<int, true>>>> salón → departamento → día → bloque prestado (HC-10) */
    private array $loans = [];

    /** @var array<string, array<string, array<int, array<int, string>>>> cohorte → campus → día → bloque → grupo */
    private array $cohortAt = [];

    /** @var array<string, array<string, int>> «materia|campus» → salón → prioridad (SC-03) */
    private array $roomDefaults = [];

    /** @var array<string, list<string>> */
    private array $rankings = [];

    /** @var list<array<string, mixed>> los grupos movibles, en el orden en que se colocan */
    private array $queue = [];

    /** @var list<string> */
    private array $overloaded = [];

    private int $candidates = 0;

    public function __construct(private readonly SolverInput $input, private readonly GroupOrder $order = new GroupOrder)
    {
        $this->checks = array_fill_keys($input->hardRules, true);
        $this->weights = $input->softWeights->weights;

        foreach ($input->campuses as $campus) {
            $this->grids[$campus->code] = Grid::of($campus);
        }

        $this->indexRooms();
        $this->indexTeachers();
        $this->indexFixedGroups();
        $this->queue = $this->prepareMovable();
    }

    /**
     * Coloca los grupos en orden hasta `deadline` (segundos del reloj dado); los que no alcanzan quedan sin programar.
     *
     * @param  Closure(): float  $clock
     * @param  (Closure(int): void)|null  $progress  avance de 0 a 100 por grupos revisados
     */
    public function run(float $deadline, Closure $clock, ?Closure $progress = null): BuildResult
    {
        $sessions = [];
        $unscheduled = [];
        $placementOrder = [];
        $timedOut = false;
        $otherDays = 0;
        $doubleHours = 0;
        $total = max(1, count($this->queue));
        $reported = -1;

        foreach ($this->queue as $index => $group) {
            if ($clock() >= $deadline) {
                $timedOut = true;
                $reason = "Se acabó el tiempo límite de la corrida ({$this->input->timeLimitSeconds} s) antes de llegar a este grupo; vuelve a correr con más tiempo.";

                foreach (array_slice($this->queue, $index) as $left) {
                    $unscheduled[$left['id']] = [$reason];
                }

                break;
            }

            if ($group['failure'] !== null) {
                $unscheduled[$group['id']] = [$group['failure']];
            } else {
                $failures = [];
                [$best, $tier] = $this->search($group, $failures);

                if ($best === null) {
                    $unscheduled[$group['id']] = $this->reasons($group, $failures);
                } else {
                    $sessions[$group['id']] = $this->place($group, $best);
                    $placementOrder[$group['id']] = count($placementOrder);
                    $patternDays = count($group['tiers'][0][0]);
                    $otherDays += $tier > 0 && count($best['option']) === $patternDays ? 1 : 0;
                    $doubleHours += count($best['option']) < $patternDays ? 1 : 0;
                }
            }

            $percent = intdiv(($index + 1) * 100, $total);

            if ($progress !== null && $percent !== $reported) {
                $progress($percent);
                $reported = $percent;
            }
        }

        return new BuildResult($sessions, $unscheduled, $placementOrder, $this->candidates, $timedOut, $otherDays, $doubleHours, $this->overloaded);
    }

    private function indexRooms(): void
    {
        foreach ($this->input->rooms as $room) {
            $this->rooms[$room->id] = [
                'campus' => $room->campusCode,
                'type' => RoomType::tryFrom($room->type) ?? RoomType::Aula,
                'capacity' => $room->capacity,
                'building' => $room->buildingId,
                'owner' => $room->ownerDepartmentId,
            ];
            $this->roomsByCampus[$room->campusCode][] = $room->id;
            $grid = $this->grids[$room->campusCode];

            foreach ($room->blocks as $block) {
                foreach ($grid->touched(Minutes::of($block->startsAt), Minutes::of($block->endsAt)) as $index) {
                    $this->roomBlocked[$room->id][$block->day][$index] = true;
                }
            }

            foreach ($room->busy as $slot) {
                $this->markRoom($room->id, $slot->day, Minutes::of($slot->startsAt), Minutes::of($slot->endsAt), self::OUTSIDE.$room->id);
            }
        }

        $ranges = [];

        foreach ($this->input->agreements as $agreement) {
            $ranges[$agreement->roomId][$agreement->toDepartmentId][$agreement->day][] = [Minutes::of($agreement->startsAt), Minutes::of($agreement->endsAt)];
        }

        foreach ($ranges as $roomId => $byDepartment) {
            $grid = isset($this->rooms[$roomId]) ? $this->grids[$this->rooms[$roomId]['campus']] : null;

            foreach ($grid === null ? [] : $byDepartment as $departmentId => $byDay) {
                foreach ($byDay as $day => $dayRanges) {
                    $this->loans[$roomId][$departmentId][$day] = $grid->covered($dayRanges);
                }
            }
        }

        foreach ($this->input->subjectRoomDefaults as $default) {
            $this->roomDefaults["{$default->subjectCode}|{$default->campusCode}"][$default->roomId] = $default->priority;
        }
    }

    /**
     * La indisponibilidad (HC-09), los preferidos (SC-04, SC-08) y la ocupación de fuera de la corrida de cada docente,
     * en la rejilla de cada campus. Una ocupación sin `campus_code` es del campus de su primer grupo, como en
     * ContractState.
     */
    private function indexTeachers(): void
    {
        $firstCampus = [];

        foreach ($this->input->groups as $group) {
            $this->groups[$group->id] = $group;

            if ($group->teacherId !== null) {
                $firstCampus[$group->teacherId] ??= $group->campusCode;
            }
        }

        foreach ($this->input->teachers as $teacher) {
            $this->honorarios[$teacher->id] = $teacher->honorarios;

            foreach ($this->grids as $code => $grid) {
                foreach ($teacher->unavailable as $slot) {
                    foreach ($grid->touched(Minutes::of($slot->startsAt), Minutes::of($slot->endsAt)) as $index) {
                        $this->teacherOff[$teacher->id][$code][$slot->day][$index] = true;
                    }
                }

                $preferred = [];

                foreach ($teacher->preferred as $slot) {
                    $preferred[$slot->day][] = [Minutes::of($slot->startsAt), Minutes::of($slot->endsAt)];
                }

                foreach ($preferred as $day => $ranges) {
                    $this->teacherPreferred[$teacher->id][$code][$day] = $grid->covered($ranges);
                }
            }

            foreach ($teacher->busy as $slot) {
                $campus = $slot->campusCode ?? $firstCampus[$teacher->id] ?? $this->input->campuses[0]->code;
                $this->markTeacher($teacher->id, $slot->day, Minutes::of($slot->startsAt), Minutes::of($slot->endsAt), self::OUTSIDE.$teacher->id, $campus, null);
            }
        }
    }

    /**
     * Los grupos fijos de la entrada ocupan su docente, sus salones y sus cohortes con sus `fixed_sessions`.
     */
    private function indexFixedGroups(): void
    {
        foreach ($this->input->groups as $group) {
            if ($group->movable) {
                continue;
            }

            foreach ($group->fixedSessions as $session) {
                $this->occupy($group, $session->day, Minutes::of($session->startsAt), Minutes::of($session->endsAt), $session->roomId);
            }
        }
    }

    /**
     * Los grupos movibles listos para colocar: su rejilla, sus unidades, sus salones ordenados y sus juegos de días, en
     * el orden de GroupOrder.
     *
     * @return list<array<string, mixed>>
     */
    private function prepareMovable(): array
    {
        $teacherHours = [];
        $cohortHours = [];

        foreach ($this->input->groups as $group) {
            foreach ($this->cohortsOf($group) as $key) {
                $cohortHours[$key] = ($cohortHours[$key] ?? 0) + $group->weeklyHours;
            }

            if ($group->movable && $group->teacherId !== null) {
                $teacherHours[$group->teacherId] = ($teacherHours[$group->teacherId] ?? 0) + $group->weeklyHours;
            }
        }

        foreach ($this->input->teachers as $teacher) {
            if (($teacherHours[$teacher->id] ?? 0) > $teacher->remainingHours) {
                $this->overloaded[] = $teacher->id;
            }
        }

        $prepared = [];
        $freeHours = [];

        foreach ($this->input->groups as $group) {
            if (! $group->movable) {
                continue;
            }

            $grid = $this->grids[$group->campusCode];
            $units = $grid->units($group->weeklyHours);
            $tiers = $units === null ? [] : SessionPattern::tiers($units, $grid->days);
            $labPractice = $grid->usesRooms && $group->needsLab && $group->practiceHours > 0 && isset($this->checks['HC-06']);
            $theory = $grid->usesRooms ? $this->rank($group, self::THEORY) : [];
            $labs = $labPractice ? $this->rank($group, self::LAB) : [];

            $entry = [
                'id' => $group->id,
                'group' => $group,
                'campus' => $group->campusCode,
                'grid' => $grid,
                'teacher' => $group->teacherId,
                'department' => $group->departmentId,
                'units' => $units ?? 0,
                'practice' => $units === null ? 0 : min($units, (int) round($group->practiceHours * 60 / $grid->unitMinutes)),
                'labPractice' => $labPractice,
                'usesRooms' => $grid->usesRooms,
                'cohorts' => $this->cohortsOf($group),
                'neighbours' => $this->neighbourCohorts($group),
                'rooms' => [self::THEORY => $theory, self::LAB => $labs],
                'rank' => [self::THEORY => array_flip($theory), self::LAB => array_flip($labs)],
                'defaults' => $this->roomDefaults["{$group->subjectCode}|{$group->campusCode}"] ?? [],
                'tiers' => $tiers,
                'failure' => $this->staticFailure($group, $grid, $units, $tiers, $theory, $labs, $labPractice),
            ];
            $entry += $this->currentOf($group, $grid);

            $teacherLoad = 0.0;

            if ($group->teacherId !== null) {
                $freeHours[$group->teacherId][$group->campusCode] ??= $this->freeHours($group->teacherId, $grid);
                $teacherLoad = ($teacherHours[$group->teacherId] ?? 0) / max(1.0, $freeHours[$group->teacherId][$group->campusCode]);
            }

            $cohortLoad = 0.0;
            $gridHours = max(1, count($grid->days) * $grid->blocks * $grid->blockMinutes / 60);

            foreach ($entry['cohorts'] as $key) {
                $cohortLoad = max($cohortLoad, ($cohortHours[$key] ?? 0) / $gridHours);
            }

            [$patternStarts, $totalStarts] = $this->patternStarts($entry);
            $entry['difficulty'] = $this->order->difficulty([
                'has_teacher' => $group->teacherId !== null,
                'pattern_starts' => $patternStarts,
                'total_starts' => $totalStarts,
                'teacher_load' => $teacherLoad,
                'rooms' => ! $grid->usesRooms ? PHP_INT_MAX : ($labPractice ? min(count($theory), count($labs)) : count($theory)),
                'weekly_hours' => $group->weeklyHours,
                'cohort_load' => $cohortLoad,
                'semester' => $group->semester,
            ]);
            $prepared[] = $entry;
        }

        usort($prepared, fn (array $a, array $b): int => [$b['difficulty'], $a['group']->semester, $b['group']->weeklyHours, $a['id']]
            <=> [$a['difficulty'], $b['group']->semester, $a['group']->weeklyHours, $b['id']]);

        return $prepared;
    }

    /**
     * Por qué un grupo no se puede colocar sin buscar: la rejilla no da sus horas, o no hay salón ni laboratorio que le
     * sirva.
     *
     * @param  list<list<array<int, int>>>  $tiers
     * @param  list<string>  $theory
     * @param  list<string>  $labs
     */
    private function staticFailure(GroupInput $group, Grid $grid, ?int $units, array $tiers, array $theory, array $labs, bool $labPractice): ?string
    {
        if ($units === null) {
            return "HC-12: la rejilla de {$grid->code} (bloques de {$grid->blockMinutes} min) no da sus {$group->weeklyHours} h exactas.";
        }

        if ($tiers === [] || $grid->blocks < $grid->blocksPerUnit) {
            return "HC-04: sus horas no caben en la jornada de {$grid->code}.";
        }

        if ($grid->usesRooms && $theory === []) {
            $inCampus = array_filter($this->roomsByCampus[$group->campusCode] ?? [], fn (string $id): bool => $group->candidateRoomIds === [] || in_array($id, $group->candidateRoomIds, true));
            $roomy = array_filter($inCampus, fn (string $id): bool => $this->rooms[$id]['capacity'] >= $group->capacity);

            return match (true) {
                $inCampus === [] => "HC-02: no hay salones de {$grid->code} que pueda usar.",
                $roomy === [] => "HC-05: ningún salón de {$grid->code} tiene lugar para sus {$group->capacity} alumnos.",
                default => "HC-10: los salones de {$grid->code} con lugar para sus {$group->capacity} alumnos son de otro departamento y no hay préstamo para el suyo.",
            };
        }

        if ($labPractice && $labs === []) {
            return "HC-06: {$grid->code} no tiene laboratorio, taller ni sala de cómputo que pueda usar con lugar para sus {$group->capacity} alumnos.";
        }

        return null;
    }

    /**
     * Las sesiones actuales del grupo en la rejilla: las ventanas por día (para preferir la hora actual), el salón de cada
     * ventana y las llaves exactas de cada sesión (para `changed`).
     *
     * @return array{currentWindows: array<string, true>, currentRooms: array<string, string>, currentSlots: array<string, true>, currentPractice: array<int, true>}
     */
    private function currentOf(GroupInput $group, Grid $grid): array
    {
        $byDay = [];
        $slots = [];
        $practice = [];

        foreach ($group->currentSessions as $session) {
            $start = Minutes::of($session->startsAt);
            $end = Minutes::of($session->endsAt);
            $byDay[$session->day][] = [$start, $end, $session->roomId];
            $slots[self::slotKey($session->day, $start, $end, $session->roomId, $session->kind)] = true;

            if ($session->kind === SessionKind::Practice) {
                $practice[$session->day] = true;
            }
        }

        $windows = [];
        $rooms = [];

        foreach ($byDay as $day => $sessions) {
            usort($sessions, fn (array $a, array $b): int => $a <=> $b);
            $merged = [];

            foreach ($sessions as [$start, $end, $roomId]) {
                $last = count($merged) - 1;

                if ($last >= 0 && $start === $merged[$last][1]) {
                    $merged[$last][1] = $end;
                    $merged[$last][2] = $merged[$last][2] === $roomId ? $roomId : null;
                } else {
                    $merged[] = [$start, $end, $roomId];
                }
            }

            foreach ($merged as [$start, $end, $roomId]) {
                $offset = $start - $grid->start;

                if ($offset < 0 || $offset % $grid->blockMinutes !== 0 || ($end - $start) % $grid->unitMinutes !== 0) {
                    continue;
                }

                $key = $day.':'.intdiv($offset, $grid->blockMinutes).':'.intdiv($end - $start, $grid->unitMinutes);
                $windows[$key] = true;

                if ($roomId !== null) {
                    $rooms[$key] = $roomId;
                }
            }
        }

        return ['currentWindows' => $windows, 'currentRooms' => $rooms, 'currentSlots' => $slots, 'currentPractice' => $practice];
    }

    /**
     * Cuántas horas de inicio del patrón tiene libres el docente (con lo fijo e indisponible), de cuántas hay.
     *
     * @param  array<string, mixed>  $group
     * @return array{int, int}
     */
    private function patternStarts(array $group): array
    {
        if ($group['tiers'] === []) {
            return [0, 1];
        }

        $grid = $group['grid'];
        $option = $group['tiers'][0][0];
        $total = max(1, $grid->blocks - max($option) * $grid->blocksPerUnit + 1);

        if ($group['teacher'] === null) {
            return [$total, $total];
        }

        $free = 0;

        for ($start = 0; $start < $total; $start++) {
            $fits = true;

            foreach ($option as $day => $units) {
                if (! $this->teacherFree($group['teacher'], $group['campus'], $day, $start, $start + $units * $grid->blocksPerUnit)) {
                    $fits = false;

                    break;
                }
            }

            $free += $fits ? 1 : 0;
        }

        return [$free, $total];
    }

    private function freeHours(string $teacherId, Grid $grid): float
    {
        $free = 0;

        foreach ($grid->days as $day) {
            for ($block = 0; $block < $grid->blocks; $block++) {
                $free += $this->teacherFree($teacherId, $grid->code, $day, $block, $block + 1) ? 1 : 0;
            }
        }

        return $free * $grid->blockMinutes / 60;
    }

    private function teacherFree(string $teacherId, string $campus, int $day, int $from, int $to): bool
    {
        $off = isset($this->checks['HC-09']) ? $this->teacherOff[$teacherId][$campus][$day] ?? null : null;
        $at = isset($this->checks['HC-01']) ? $this->teacherAt[$teacherId][$campus][$day] ?? null : null;

        for ($block = $from; $block < $to; $block++) {
            if (isset($off[$block]) || isset($at[$block])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Los salones del campus que le sirven al grupo para teoría o para práctica de laboratorio, en orden: por defecto
     * de la materia, tipo, capacidad más ajustada e id. Los de otro departamento entran solo si hay algún préstamo para
     * el suyo (HC-10); cuál bloque cubre se revisa al colocar.
     *
     * @return list<string>
     */
    private function rank(GroupInput $group, string $kind): array
    {
        $key = implode('|', [$group->campusCode, $group->capacity, $group->departmentId, $group->subjectCode, $kind, implode(',', $group->candidateRoomIds)]);

        if (isset($this->rankings[$key])) {
            return $this->rankings[$key];
        }

        $defaults = $this->roomDefaults["{$group->subjectCode}|{$group->campusCode}"] ?? [];
        $candidates = $group->candidateRoomIds === [] ? null : array_flip($group->candidateRoomIds);
        $sortKeys = [];

        foreach ($this->roomsByCampus[$group->campusCode] ?? [] as $roomId) {
            $room = $this->rooms[$roomId];

            if ($candidates !== null && ! isset($candidates[$roomId])) {
                continue;
            }

            if (isset($this->checks['HC-05']) && $room['capacity'] < $group->capacity) {
                continue;
            }

            if (isset($this->checks['HC-10']) && $room['owner'] !== null && $room['owner'] !== $group->departmentId && ! isset($this->loans[$roomId][$group->departmentId])) {
                continue;
            }

            if ($kind === self::LAB && ! $room['type']->isPracticeSpace()) {
                continue;
            }

            $slack = $room['capacity'] - $group->capacity;
            $sortKeys[$roomId] = [$defaults[$roomId] ?? PHP_INT_MAX, $this->typeRank($kind, $roomId), $slack < 0 ? PHP_INT_MAX : $slack, $roomId];
        }

        uasort($sortKeys, fn (array $a, array $b): int => $a <=> $b);

        return $this->rankings[$key] = array_map('strval', array_keys($sortKeys));
    }

    /**
     * El tipo de salón en el orden: para teoría, aula, luego espacio de práctica y luego lo demás (auditorio); para la
     * práctica de laboratorio todos los que entran valen igual.
     */
    private function typeRank(string $kind, string $roomId): int
    {
        $type = $this->rooms[$roomId]['type'];

        return match (true) {
            $kind === self::LAB, $type === RoomType::Aula => 0,
            $type->isPracticeSpace() => 1,
            default => 2,
        };
    }

    /**
     * El mejor candidato del primer nivel de días donde alguno cabe, y el nivel; o nada, con los motivos de cada candidato
     * que no cupo en `$failures`.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, array{count: int, details: array<string, true>}>  $failures
     * @return array{array<string, mixed>|null, int}
     */
    private function search(array $group, array &$failures): array
    {
        $grid = $group['grid'];
        $cells = [];

        foreach ($group['tiers'] as $tierIndex => $tier) {
            $best = null;

            foreach ($tier as $option) {
                $last = $grid->blocks - max($option) * $grid->blocksPerUnit;

                for ($start = 0; $start <= $last; $start++) {
                    $this->candidates++;
                    $candidate = $this->candidate($group, $option, $start, $cells, $failures);

                    if ($candidate !== null && ($best === null || [$candidate['score'], -$candidate['match']] < [$best['score'], -$best['match']])) {
                        $best = $candidate;
                    }
                }
            }

            if ($best !== null) {
                return [$best, $tierIndex];
            }
        }

        return [null, -1];
    }

    /**
     * Un candidato: el grupo a la hora `start` en los días de la opción, con su salón por día y su puntaje estimado; o
     * null si algo no cabe (el motivo va a `$failures`).
     *
     * @param  array<string, mixed>  $group
     * @param  array<int, int>  $option
     * @param  array<string, array<string, mixed>>  $cells
     * @param  array<string, array{count: int, details: array<string, true>}>  $failures
     * @return array<string, mixed>|null
     */
    private function candidate(array $group, array $option, int $start, array &$cells, array &$failures): ?array
    {
        $score = 0.0;
        $match = 0;
        $dayCells = [];

        foreach ($option as $day => $units) {
            $key = "{$day}:{$start}:{$units}";
            $cell = $cells[$key] ??= $this->cell($group, $day, $start, $units);

            if (isset($cell['fail'])) {
                self::fail($failures, $cell['fail'], $cell['detail'] ?? null);

                return null;
            }

            $score += $cell['soft'];
            $match += isset($group['currentWindows'][$key]) ? 1 : 0;
            $dayCells[$day] = $cell;
        }

        $rooms = array_fill_keys(array_keys($option), null);
        $practice = $this->practiceDays($group, $option);

        if ($group['usesRooms']) {
            $chosen = $this->chooseRooms($group, $option, $start, $dayCells);

            if (is_string($chosen)) {
                self::fail($failures, $chosen, null);

                return null;
            }

            [$rooms, $practice] = $chosen;
            $score += $this->roomScore($group, $option, $rooms, $dayCells);
        }

        return ['score' => $score, 'match' => $match, 'option' => $option, 'start' => $start, 'rooms' => $rooms, 'practice' => $practice];
    }

    /**
     * Un día del candidato sin salón: el docente (HC-09, HC-01) y las cohortes (HC-03) libres en esos bloques, y lo que
     * cuesta en el puntaje blando lo que no depende del salón.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function cell(array $group, int $day, int $start, int $units): array
    {
        $grid = $group['grid'];
        $campus = $group['campus'];
        $end = $start + $units * $grid->blocksPerUnit;
        $teacherId = $group['teacher'];

        if ($teacherId !== null) {
            $off = isset($this->checks['HC-09']) ? $this->teacherOff[$teacherId][$campus][$day] ?? null : null;
            $at = isset($this->checks['HC-01']) ? $this->teacherAt[$teacherId][$campus][$day] ?? null : null;

            for ($block = $start; $block < $end; $block++) {
                if (isset($off[$block])) {
                    return ['fail' => 'HC-09'];
                }

                if (isset($at[$block])) {
                    return ['fail' => 'HC-01', 'detail' => $at[$block]];
                }
            }
        }

        if (isset($this->checks['HC-03'])) {
            foreach ($group['cohorts'] as $key) {
                $at = $this->cohortAt[$key][$campus][$day] ?? null;

                for ($block = $start; $block < $end; $block++) {
                    if (isset($at[$block])) {
                        return ['fail' => 'HC-03', 'detail' => "{$key}|{$at[$block]}"];
                    }
                }
            }
        }

        $hour = $grid->blockMinutes / 60;
        $soft = 0.0;

        if ($this->weights['SC-01'] > 0) {
            foreach ($group['cohorts'] as $key) {
                $soft += $this->weights['SC-01'] * $hour * self::gapDelta($this->cohortAt[$key][$campus][$day] ?? [], $start, $end);
            }
        }

        if ($this->weights['SC-07'] > 0) {
            foreach ($group['neighbours'] as $key) {
                $at = $this->cohortAt[$key][$campus][$day] ?? [];

                for ($block = $start; $block < $end; $block++) {
                    $soft += isset($at[$block]) ? $this->weights['SC-07'] * $hour : 0;
                }
            }
        }

        $previous = null;
        $next = null;

        if ($teacherId !== null) {
            $soft += $this->weights['SC-05'] * $hour * self::gapDelta($this->teacherAt[$teacherId][$campus][$day] ?? [], $start, $end);
            $preferred = $this->teacherPreferred[$teacherId][$campus] ?? null;

            if ($preferred !== null) {
                $weight = $this->weights['SC-04'] + (($this->honorarios[$teacherId] ?? false) ? $this->weights['SC-08'] : 0);

                for ($block = $start; $block < $end; $block++) {
                    $soft += isset($preferred[$day][$block]) ? 0 : $weight * $hour;
                }
            }

            [$previous, $next] = $this->neighbours($teacherId, $day, $grid->minuteOf($start), $grid->minuteOf($end));

            foreach ([[$previous, $grid->minuteOf($start) - ($previous[1] ?? 0)], [$next, ($next[0] ?? 0) - $grid->minuteOf($end)]] as [$span, $gap]) {
                if ($span !== null && $span[2] !== $campus && $gap < $this->input->travelMinutes) {
                    $soft += $this->weights['SC-09'];
                }
            }
        }

        return [
            'soft' => $soft,
            'before' => $previous !== null && $previous[1] === $grid->minuteOf($start) ? $previous[3] : null,
            'after' => $next !== null && $next[0] === $grid->minuteOf($end) ? $next[3] : null,
        ];
    }

    /**
     * La sesión del docente que termina más tarde antes de `from` y la que empieza más temprano después de `to`.
     *
     * @return array{array{int, int, string, ?string}|null, array{int, int, string, ?string}|null}
     */
    private function neighbours(string $teacherId, int $day, int $from, int $to): array
    {
        $previous = null;
        $next = null;

        foreach ($this->teacherSpans[$teacherId][$day] ?? [] as $span) {
            if ($span[1] <= $from && ($previous === null || $span[1] > $previous[1])) {
                $previous = $span;
            }

            if ($span[0] >= $to && ($next === null || $span[0] < $next[0])) {
                $next = $span;
            }
        }

        return [$previous, $next];
    }

    /**
     * Cuántos bloques de hueco agrega (o quita, en negativo) ocupar [start, end) en un día con esos bloques ocupados.
     *
     * @param  array<int, mixed>  $occupied
     */
    private static function gapDelta(array $occupied, int $start, int $end): int
    {
        if ($occupied === []) {
            return 0;
        }

        $blocks = array_keys($occupied);
        $min = min($blocks);
        $max = max($blocks);
        $count = count($blocks);
        $before = $max - $min + 1 - $count;
        $after = max($max, $end - 1) - min($min, $start) + 1 - $count - ($end - $start);

        return max(0, $after) - $before;
    }

    /**
     * Las unidades de práctica por día cuando no hace falta laboratorio: en los días donde ya había práctica y, si no,
     * en los últimos.
     *
     * @param  array<string, mixed>  $group
     * @param  array<int, int>  $option
     * @return array<int, int>
     */
    private function practiceDays(array $group, array $option): array
    {
        $practice = array_fill_keys(array_keys($option), 0);
        $left = $group['practice'];
        $days = array_reverse(array_keys($option));
        usort($days, fn (int $a, int $b): int => [isset($group['currentPractice'][$a]) ? 0 : 1, -$a] <=> [isset($group['currentPractice'][$b]) ? 0 : 1, -$b]);

        foreach ($days as $day) {
            $take = min($option[$day], $left);
            $practice[$day] = $take;
            $left -= $take;
        }

        return $practice;
    }

    /**
     * El salón de cada día y las unidades de práctica de cada día, o el código del motivo si no hay salón (`rooms`) o
     * laboratorio (`HC-06`) libre.
     *
     * @param  array<string, mixed>  $group
     * @param  array<int, int>  $option
     * @param  array<int, array<string, mixed>>  $cells
     * @return array{array<int, string>, array<int, int>}|string
     */
    private function chooseRooms(array $group, array $option, int $start, array $cells): array|string
    {
        $theory = [];

        foreach ($option as $day => $units) {
            $theory[$day] = $this->roomFor($group, self::THEORY, $day, $start, $units, $cells[$day]);
        }

        if (! $group['labPractice']) {
            if (in_array(null, $theory, true)) {
                return 'rooms';
            }

            $rooms = $theory;
            $this->unify($group, self::THEORY, $option, $start, $rooms, array_keys($option), $cells);

            return [$rooms, $this->practiceDays($group, $option)];
        }

        $labs = [];

        foreach ($option as $day => $units) {
            $labs[$day] = $this->roomFor($group, self::LAB, $day, $start, $units, $cells[$day]);
        }

        $capable = array_keys(array_filter($labs, fn (?string $room): bool => $room !== null));
        usort($capable, fn (int $a, int $b): int => [$theory[$a] === null ? 0 : 1, isset($group['currentPractice'][$a]) ? 0 : 1, $a]
            <=> [$theory[$b] === null ? 0 : 1, isset($group['currentPractice'][$b]) ? 0 : 1, $b]);
        $practice = array_fill_keys(array_keys($option), 0);
        $left = $group['practice'];

        foreach ($capable as $day) {
            if ($left === 0) {
                break;
            }

            $practice[$day] = min($option[$day], $left);
            $left -= $practice[$day];
        }

        if ($left > 0) {
            return 'HC-06';
        }

        $rooms = [];
        $labDays = [];
        $theoryDays = [];

        foreach ($option as $day => $units) {
            if ($practice[$day] > 0) {
                $rooms[$day] = $labs[$day];
                $labDays[] = $day;
            } elseif ($theory[$day] !== null) {
                $rooms[$day] = $theory[$day];
                $theoryDays[] = $day;
            } else {
                return 'rooms';
            }
        }

        $this->unify($group, self::LAB, $option, $start, $rooms, $labDays, $cells);
        $this->unify($group, self::THEORY, $option, $start, $rooms, $theoryDays, $cells);

        return [$rooms, $practice];
    }

    /**
     * El primer salón libre de la lista para ese día y esas horas. Si el docente tiene otra sesión pegada en otro
     * edificio, uno igual de bueno en ese edificio (SC-06); y el actual del grupo si está libre, es igual de bueno y no
     * agrega un cambio de edificio.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $cell
     */
    private function roomFor(array $group, string $kind, int $day, int $start, int $units, array $cell): ?string
    {
        $end = $start + $units * $group['grid']->blocksPerUnit;
        $first = $this->firstFree($group, $kind, $day, $start, $end, null);
        $near = $cell['before'] ?? $cell['after'] ?? null;

        if ($first === null) {
            return null;
        }

        if ($near !== null && $this->rooms[$first]['building'] !== $near) {
            $nearby = $this->firstFree($group, $kind, $day, $start, $end, $near);
            $first = $nearby !== null && $this->sameTier($group, $kind, $nearby, $first) ? $nearby : $first;
        }

        $current = $group['currentRooms']["{$day}:{$start}:{$units}"] ?? null;

        if ($current === null || $current === $first || ! isset($group['rank'][$kind][$current])
            || ($near !== null && $this->rooms[$current]['building'] !== $near && $this->rooms[$first]['building'] === $near)) {
            return $first;
        }

        return $this->sameTier($group, $kind, $current, $first) && $this->roomFree($group, $current, $day, $start, $end) ? $current : $first;
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function firstFree(array $group, string $kind, int $day, int $start, int $end, ?string $building): ?string
    {
        foreach ($group['rooms'][$kind] as $roomId) {
            if (($building === null || $this->rooms[$roomId]['building'] === $building) && $this->roomFree($group, $roomId, $day, $start, $end)) {
                return $roomId;
            }
        }

        return null;
    }

    /**
     * Si dos salones están en el mismo escalón del orden: misma prioridad por defecto y mismo tipo.
     *
     * @param  array<string, mixed>  $group
     */
    private function sameTier(array $group, string $kind, string $a, string $b): bool
    {
        return [$group['defaults'][$a] ?? PHP_INT_MAX, $this->typeRank($kind, $a)] === [$group['defaults'][$b] ?? PHP_INT_MAX, $this->typeRank($kind, $b)];
    }

    /**
     * Un mismo salón en todos esos días si alguno de los elegidos (del mismo escalón que el mejor) queda libre en todos
     * sin agregar cambios de edificio al docente (SC-06); si no, uno del edificio del mejor en los días que quedaron en
     * otro edificio y donde el docente no tiene una sesión pegada.
     *
     * @param  array<string, mixed>  $group
     * @param  array<int, int>  $option
     * @param  array<int, string>  $rooms
     * @param  list<int>  $days
     * @param  array<int, array<string, mixed>>  $cells
     */
    private function unify(array $group, string $kind, array $option, int $start, array &$rooms, array $days, array $cells): void
    {
        if (count($days) < 2) {
            return;
        }

        $chosen = array_values(array_unique(array_map(fn (int $day): string => $rooms[$day], $days)));

        if (count($chosen) === 1) {
            return;
        }

        $rank = $group['rank'][$kind];
        usort($chosen, fn (string $a, string $b): int => ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX));
        $bpu = $group['grid']->blocksPerUnit;
        $changes = fn (array $assigned): int => array_sum(array_map(fn (int $day): int => $this->buildingChanges($cells[$day], $assigned[$day]), $days));
        $before = $changes($rooms);

        foreach ($chosen as $roomId) {
            if (! $this->sameTier($group, $kind, $roomId, $chosen[0]) || $changes(array_fill_keys($days, $roomId)) > $before) {
                continue;
            }

            $everyDay = true;

            foreach ($days as $day) {
                if ($rooms[$day] !== $roomId && ! $this->roomFree($group, $roomId, $day, $start, $start + $option[$day] * $bpu)) {
                    $everyDay = false;

                    break;
                }
            }

            if ($everyDay) {
                foreach ($days as $day) {
                    $rooms[$day] = $roomId;
                }

                return;
            }
        }

        $building = $this->rooms[$chosen[0]]['building'];

        foreach ($days as $day) {
            if ($this->rooms[$rooms[$day]]['building'] === $building || $cells[$day]['before'] !== null || $cells[$day]['after'] !== null) {
                continue;
            }

            $nearby = $this->firstFree($group, $kind, $day, $start, $start + $option[$day] * $bpu, $building);
            $rooms[$day] = $nearby !== null && $this->sameTier($group, $kind, $nearby, $rooms[$day]) ? $nearby : $rooms[$day];
        }
    }

    /**
     * Los cambios de edificio con las sesiones del docente pegadas a la del día si queda en ese salón (SC-06).
     *
     * @param  array<string, mixed>  $cell
     */
    private function buildingChanges(array $cell, string $roomId): int
    {
        $building = $this->rooms[$roomId]['building'];

        return ($cell['before'] !== null && $cell['before'] !== $building ? 1 : 0) + ($cell['after'] !== null && $cell['after'] !== $building ? 1 : 0);
    }

    /**
     * Si el salón está libre en esos bloques: sin otra sesión (HC-02), sin bloqueo (HC-11) y, si es de otro
     * departamento, con préstamo aceptado para el del grupo en cada bloque (HC-10).
     *
     * @param  array<string, mixed>  $group
     */
    private function roomFree(array $group, string $roomId, int $day, int $start, int $end): bool
    {
        $at = isset($this->checks['HC-02']) ? $this->roomAt[$roomId][$day] ?? null : null;
        $blocked = isset($this->checks['HC-11']) ? $this->roomBlocked[$roomId][$day] ?? null : null;
        $owner = $this->rooms[$roomId]['owner'];
        $loan = isset($this->checks['HC-10']) && $owner !== null && $owner !== $group['department'] ? $this->loans[$roomId][$group['department']][$day] ?? [] : null;

        for ($block = $start; $block < $end; $block++) {
            if (isset($at[$block]) || isset($blocked[$block]) || ($loan !== null && ! isset($loan[$block]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lo que cuestan los salones elegidos: horas fuera del salón por defecto (SC-03) y cambios de edificio pegados a otra
     * sesión del docente (SC-06).
     *
     * @param  array<string, mixed>  $group
     * @param  array<int, int>  $option
     * @param  array<int, string|null>  $rooms
     * @param  array<int, array<string, mixed>>  $cells
     */
    private function roomScore(array $group, array $option, array $rooms, array $cells): float
    {
        $score = 0.0;
        $hours = $group['grid']->unitMinutes / 60;

        foreach ($option as $day => $units) {
            $roomId = $rooms[$day];

            if ($roomId === null) {
                continue;
            }

            if ($group['defaults'] !== [] && ! isset($group['defaults'][$roomId])) {
                $score += $this->weights['SC-03'] * $units * $hours;
            }

            $score += $this->weights['SC-06'] * $this->buildingChanges($cells[$day], $roomId);
        }

        return $score;
    }

    /**
     * Coloca el candidato: las sesiones de una hora del grupo y su ocupación en los índices.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $best
     * @return list<ProposedSession>
     */
    private function place(array $group, array $best): array
    {
        $grid = $group['grid'];
        $sessions = [];

        foreach ($best['option'] as $day => $units) {
            $roomId = $best['rooms'][$day];

            for ($unit = 0; $unit < $units; $unit++) {
                $from = $grid->minuteOf($best['start'] + $unit * $grid->blocksPerUnit);
                $to = $from + $grid->unitMinutes;
                $kind = ! $group['usesRooms'] ? SessionKind::Virtual : ($unit < $best['practice'][$day] ? SessionKind::Practice : SessionKind::Theory);
                $sessions[] = new ProposedSession($group['id'], $day, Minutes::format($from), Minutes::format($to), $roomId, $kind,
                    ! isset($group['currentSlots'][self::slotKey($day, $from, $to, $roomId, $kind)]));
                $this->occupy($group['group'], $day, $from, $to, $roomId);
            }
        }

        return $sessions;
    }

    /**
     * Una sesión de un grupo (fijo o recién colocado) ocupa a su docente, su salón y sus cohortes.
     */
    private function occupy(GroupInput $group, int $day, int $from, int $to, ?string $roomId): void
    {
        if ($group->teacherId !== null) {
            $this->markTeacher($group->teacherId, $day, $from, $to, $group->id, $group->campusCode, $roomId === null ? null : $this->rooms[$roomId]['building'] ?? null);
        }

        if ($roomId !== null && isset($this->rooms[$roomId])) {
            $this->markRoom($roomId, $day, $from, $to, $group->id);
        }

        foreach ($this->cohortsOf($group) as $key) {
            foreach ($this->grids as $code => $grid) {
                foreach ($grid->touched($from, $to) as $block) {
                    $this->cohortAt[$key][$code][$day][$block] ??= $group->id;
                }
            }
        }
    }

    private function markTeacher(string $teacherId, int $day, int $from, int $to, string $owner, string $campus, ?string $building): void
    {
        foreach ($this->grids as $code => $grid) {
            foreach ($grid->touched($from, $to) as $block) {
                $this->teacherAt[$teacherId][$code][$day][$block] ??= $owner;
            }
        }

        $this->teacherSpans[$teacherId][$day][] = [$from, $to, $campus, $building];
    }

    private function markRoom(string $roomId, int $day, int $from, int $to, string $owner): void
    {
        foreach ($this->grids[$this->rooms[$roomId]['campus']]->touched($from, $to) as $block) {
            $this->roomAt[$roomId][$day][$block] ??= $owner;
        }
    }

    /**
     * Las claves de cohorte del grupo. La de un paquete lleva el campus del grupo, como la cuenta HC-03 (Cohorts): la
     * forma anterior, `package:<id>:<n>`, también.
     *
     * @return list<string>
     */
    private function cohortsOf(GroupInput $group): array
    {
        return array_values(array_unique(array_map(function (string $key) use ($group): string {
            $parts = explode(':', $key);

            return $parts[0] === 'package' && in_array(count($parts), [3, 4], true) ? "package:{$parts[1]}:{$group->campusCode}:".end($parts) : $key;
        }, $group->cohorts)));
    }

    /**
     * Las cohortes de plan de los semestres de al lado (SC-07).
     *
     * @return list<string>
     */
    private function neighbourCohorts(GroupInput $group): array
    {
        $keys = [];

        foreach ($group->cohorts as $key) {
            $parts = explode(':', $key);

            if ($parts[0] === 'plan' && count($parts) === 4) {
                $keys[] = "plan:{$parts[1]}:".((int) $parts[2] - 1).":{$parts[3]}";
                $keys[] = "plan:{$parts[1]}:".((int) $parts[2] + 1).":{$parts[3]}";
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, array{count: int, details: array<string, true>}>  $failures
     */
    private static function fail(array &$failures, string $code, ?string $detail): void
    {
        $failures[$code]['count'] = ($failures[$code]['count'] ?? 0) + 1;
        $failures[$code]['details'] ??= [];

        if ($detail !== null && count($failures[$code]['details']) < 3) {
            $failures[$code]['details'][$detail] = true;
        }
    }

    /**
     * Los motivos de un grupo que no cupo, del que más candidatos descartó al que menos (hasta tres), con la regla al
     * frente.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, array{count: int, details: array<string, true>}>  $failures
     * @return list<string>
     */
    private function reasons(array $group, array $failures): array
    {
        if ($failures === []) {
            return ["HC-04: sus horas no caben en la jornada de {$group['campus']}."];
        }

        uasort($failures, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $days = self::daysLabel(array_keys($group['tiers'][0][0]));
        $others = count($group['tiers']) > 1 ? ' ni en otros días' : '';
        $reasons = [];

        foreach (array_slice($failures, 0, 3, true) as $code => $failure) {
            $details = array_keys($failure['details']);

            $reasons[] = match ($code) {
                'HC-09' => "HC-09: el docente no tiene disponibilidad a una misma hora en {$days}{$others}.",
                'HC-01' => 'HC-01: el docente ya da clase a las horas que quedan'.self::with(array_map(fn (string $owner): string => 'con '.$this->labelOf($owner), $details)).'.',
                'HC-03' => 'HC-03: choca con su cohorte'.self::with(array_map(fn (string $detail): string => $this->cohortLabel($detail), $details)).'.',
                'HC-06' => "HC-06: no queda laboratorio, taller ni sala de cómputo libre para sus {$group['group']->practiceHours} h de práctica a una hora en que el docente y su cohorte pueden.",
                default => "HC-02: no queda salón libre (sin ocupar, sin bloqueo y, si es de otro departamento, con préstamo) para sus {$group['group']->capacity} alumnos a una hora en que el docente y su cohorte pueden.",
            };
        }

        return $reasons;
    }

    /**
     * @param  list<string>  $labels
     */
    private static function with(array $labels): string
    {
        $labels = array_values(array_unique($labels));

        return $labels === [] ? '' : ' ('.implode('; ', $labels).')';
    }

    private function labelOf(string $groupId): string
    {
        if (str_starts_with($groupId, self::OUTSIDE)) {
            return 'clases fuera de esta corrida';
        }

        $group = $this->groups[$groupId] ?? null;

        return $group === null ? $groupId : "{$group->subjectCode}, grupo {$groupId}";
    }

    /**
     * «el paquete #2: MAT-1, grupo g7» o «el semestre 3 del plan p1: MAT-2, grupo g9».
     */
    private function cohortLabel(string $detail): string
    {
        [$key, $owner] = explode('|', $detail, 2);
        $parts = explode(':', $key);
        $what = match (true) {
            $parts[0] === 'package' => 'el paquete #'.end($parts),
            $parts[0] === 'plan' && count($parts) === 4 => "el semestre {$parts[2]} del plan {$parts[1]}",
            default => "la cohorte {$key}",
        };

        return "{$what}: ".$this->labelOf($owner);
    }

    /**
     * «lunes a jueves», o «lunes, miércoles y viernes».
     *
     * @param  list<int>  $days
     */
    private static function daysLabel(array $days): string
    {
        $names = array_map(fn (int $day): string => Minutes::dayName($day), $days);

        if (count($days) > 2 && end($days) - $days[0] === count($days) - 1) {
            return $names[0].' a '.end($names);
        }

        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names).' y '.$last;
    }

    private static function slotKey(int $day, int $from, int $to, ?string $roomId, SessionKind $kind): string
    {
        return "{$day}|{$from}|{$to}|{$roomId}|{$kind->value}";
    }
}
