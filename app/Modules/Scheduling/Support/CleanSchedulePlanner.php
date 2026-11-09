<?php

namespace App\Modules\Scheduling\Support;

use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Validation\Minutes;
use RuntimeException;

/**
 * El horario limpio de la demo por puntos (punto «asignacion», #338): coloca las sesiones de cada grupo para que la
 * carga pueda pasar a asignación sin choques duros y que en el punto siguiente haya docentes que no choquen. Por cada
 * grupo, en el orden en que llegan:
 *
 * - sus horas exactas (HC-12) en sesiones de dos horas y una última con lo que sobre, en días distintos, con la
 *   práctica de las materias con laboratorio aparte (`pieces()`); primero busca la misma hora en todos sus días y, si
 *   no queda, cada sesión a la hora que quepa, porque con un semestre lleno la misma hora deja huecos sueltos;
 * - dentro de la ventana del campus y alineadas a sus bloques desde `dayStart` (HC-04);
 * - sin encimarse con los grupos del mismo plan y semestre que son su misma opción (el primer grupo de cada materia
 *   con los primeros, el segundo con los segundos), así el alumno tipo siempre tiene combinación (HC-03);
 * - con un salón posible para cada sesión presencial, del tipo y la capacidad que pide (HC-02, HC-05, HC-06; los
 *   bloqueos HC-11 los trae `RoomSupply`), que se aparta pero no se guarda: el salón lo asigna el punto «cierre»;
 * - sin pasar del tope de grupos simultáneos de su departamento (`$departmentCaps`), contado sobre todos los campus
 *   que coloca la misma instancia, porque un docente da clase en los dos (HC-01).
 *
 * Las horas de inicio se reparten por plan y semestre y por materia, para no amontonar a todos a las 7:00. Pura y
 * determinista: no lee la base; la misma entrada da el mismo horario. Si un grupo no cabe, falla con el grupo y lo que
 * lo impidió.
 */
final class CleanSchedulePlanner
{
    /** El tope de grupos simultáneos de un departamento, como parte de sus docentes activos de adscripción primaria. */
    public const float DEPARTMENT_SHARE = 0.7;

    /** @var array<string, array<int, array<int, int>>> grupos de cada departamento por día y bloque, en todos los campus */
    private array $departmentLoad = [];

    /** @var array<string, list<array{string, int, int, int}>> salones apartados por grupo: [salón, día, inicio, fin] */
    private array $reservations = [];

    /**
     * @param  array<string, int>  $departmentCaps  grupos simultáneos por departamento; uno que no aparece no tiene tope
     */
    public function __construct(private readonly array $departmentCaps = []) {}

    /**
     * Las sesiones de una materia: `[minutos, tipo, espacio de práctica]`, de dos horas y una última con lo que sobre;
     * la práctica de una materia con laboratorio va aparte, en espacio de práctica (HC-06). Si salen más sesiones que
     * días, prueba sesiones de tres y de cuatro horas antes de juntar todo en sesiones de teoría repartidas en los días.
     *
     * @return list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>
     */
    public static function pieces(int $theoryHours, int $practiceHours, bool $needsLab, int $dayCount): array
    {
        foreach ([2, 3, 4] as $pieceHours) {
            $pieces = $needsLab && $practiceHours > 0
                ? [...self::split($theoryHours, SessionKind::Teoria, false, $pieceHours), ...self::split($practiceHours, SessionKind::Practica, true, $pieceHours)]
                : self::split($theoryHours + $practiceHours, SessionKind::Teoria, false, $pieceHours);

            if (count($pieces) <= $dayCount) {
                return $pieces;
            }
        }

        return self::split($theoryHours + $practiceHours, SessionKind::Teoria, false, (int) ceil(($theoryHours + $practiceHours) / max(1, $dayCount)));
    }

    /**
     * Un grupo que ya trae sesiones (`sessions`) no se mueve: cuenta en su plan y semestre, en su departamento y, si
     * alguna no tiene salón, aparta uno para ella; no sale en el resultado.
     *
     * @param  list<array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>, sessions?: list<array{day: int, start: int, end: int, kind: SessionKind, roomId: ?string}>}>  $groups
     * @param  list<int>  $days
     * @param  RoomSupply|null  $supply  la oferta de salones del campus; sin ella (campus sin salones) no se revisan
     * @return array<string, list<array{day: int, start: int, end: int, kind: SessionKind}>> por id de grupo
     *
     * @throws RuntimeException si un grupo no tiene horas o no cabe sin choque
     */
    public function plan(array $groups, array $days, int $dayStart, int $dayEnd, int $blockMinutes, ?RoomSupply $supply = null): array
    {
        $starts = $dayEnd - $blockMinutes >= $dayStart ? range($dayStart, $dayEnd - $blockMinutes, $blockMinutes) : [];
        $variants = [];
        $ordinals = [];
        $taken = [];
        $plan = [];

        foreach ($groups as $group) {
            $cohort = $group['cohort'];
            $ordinals[$cohort][$group['subject']] ??= count($ordinals[$cohort] ?? []);
            $variant = $variants[$cohort][$group['subject']] = ($variants[$cohort][$group['subject']] ?? -1) + 1;
            $option = "{$cohort}|{$variant}";

            if (isset($group['sessions'])) {
                $this->keep($group, $group['sessions'], $taken[$option], $blockMinutes, $supply);

                continue;
            }

            if ($group['pieces'] === []) {
                throw new RuntimeException("El horario limpio no puede colocar {$group['label']}: su materia no tiene horas de teoría ni de práctica.");
            }

            $ordinal = $ordinals[$cohort][$group['subject']];
            $plan[$group['id']] = $this->placeGroup($group, $this->rotate($starts, $cohort, $ordinal, $variant), $days, $ordinal, $dayEnd, $blockMinutes, $taken[$option], $supply);
        }

        return $plan;
    }

    /**
     * @param  array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}  $group
     * @param  list<int>  $starts
     * @param  list<int>  $days
     * @param  array<int, list<array{int, int}>>|null  $taken  lo que ya ocupa esta opción del plan y semestre, por día
     * @return list<array{day: int, start: int, end: int, kind: SessionKind}>
     */
    private function placeGroup(array $group, array $starts, array $days, int $ordinal, int $dayEnd, int $blockMinutes, ?array &$taken, ?RoomSupply $supply): array
    {
        $taken ??= [];
        $patterns = $this->dayPatterns(count($group['pieces']), $days, $ordinal);
        $blocked = ['días' => $patterns === [] ? 1 : 0, 'ventana' => 0, 'semestre' => 0, 'departamento' => 0, 'salón' => 0];
        $longest = max(array_column($group['pieces'], 'minutes'));

        foreach ($starts as $start) {
            if ($start + $longest > $dayEnd) {
                $blocked['ventana']++;

                continue;
            }

            foreach ($patterns as $pattern) {
                $sessions = [];

                foreach ($group['pieces'] as $index => $piece) {
                    $sessions[] = ['day' => $pattern[$index], 'start' => $start, 'end' => $start + $piece['minutes'], 'kind' => $piece['kind']];
                }

                $reason = $this->blocker($group, $sessions, $taken, $blockMinutes, $supply);

                if ($reason === null) {
                    return $this->commit($group, $sessions, $taken, $blockMinutes);
                }

                $blocked[$reason]++;
            }
        }

        $sessions = $patterns === [] ? null : $this->placeLoose($group, $starts, $days, $ordinal, $dayEnd, $blockMinutes, $taken, $supply, $blocked);

        if ($sessions !== null) {
            return $this->commit($group, $sessions, $taken, $blockMinutes);
        }

        $reasons = array_filter($blocked);
        arsort($reasons);

        throw new RuntimeException(sprintf(
            'El horario limpio no encontró lugar para %s (%d sesiones, %d lugares): lo impidieron %s.',
            $group['label'],
            count($group['pieces']),
            $group['capacity'],
            implode(', ', array_map(fn (string $reason, int $times): string => "{$reason} ({$times})", array_keys($reasons), $reasons)) ?: 'la falta de días',
        ));
    }

    /**
     * Las sesiones que el grupo ya tiene: ocupan su plan y semestre y su departamento, y a las que no tienen salón se
     * les aparta uno si queda (las que sí tienen ya están en la oferta).
     *
     * @param  array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}  $group
     * @param  list<array{day: int, start: int, end: int, kind: SessionKind, roomId: ?string}>  $sessions
     * @param  array<int, list<array{int, int}>>|null  $taken
     */
    private function keep(array $group, array $sessions, ?array &$taken, int $blockMinutes, ?RoomSupply $supply): void
    {
        $taken ??= [];

        foreach ($sessions as $session) {
            if ($session['roomId'] === null && $session['kind'] !== SessionKind::Virtual) {
                $supply?->reserve($session['day'], $session['start'], $session['end'], $group['capacity'], $session['kind'] === SessionKind::Practica, $group['department']);
            }
        }

        $this->commit($group, $sessions, $taken, $blockMinutes);
    }

    /**
     * Cada sesión por su lado, de la más larga a la más corta: el primer día libre y la primera hora que quepa, en días
     * distintos. Lo que aparta en salones se suelta si al final una sesión no cabe.
     *
     * @param  array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}  $group
     * @param  list<int>  $starts
     * @param  list<int>  $days
     * @param  array<int, list<array{int, int}>>  $taken
     * @param  array<string, int>  $blocked
     * @return list<array{day: int, start: int, end: int, kind: SessionKind}>|null en el orden de las piezas del grupo
     */
    private function placeLoose(array $group, array $starts, array $days, int $ordinal, int $dayEnd, int $blockMinutes, array $taken, ?RoomSupply $supply, array &$blocked): ?array
    {
        $order = array_keys($group['pieces']);
        usort($order, fn (int $a, int $b): int => [$group['pieces'][$b]['minutes'], $a] <=> [$group['pieces'][$a]['minutes'], $b]);
        $rotated = [...array_slice($days, $ordinal % count($days)), ...array_slice($days, 0, $ordinal % count($days))];
        $placed = [];
        $usedDays = [];

        foreach ($order as $index) {
            $piece = $group['pieces'][$index];
            $single = ['pieces' => [$piece]] + $group;
            $found = null;

            foreach ($rotated as $day) {
                if (isset($usedDays[$day])) {
                    continue;
                }

                foreach ($starts as $start) {
                    if ($start + $piece['minutes'] > $dayEnd) {
                        $blocked['ventana']++;

                        continue;
                    }

                    $session = ['day' => $day, 'start' => $start, 'end' => $start + $piece['minutes'], 'kind' => $piece['kind']];
                    $reason = $this->blocker($single, [$session], $taken, $blockMinutes, $supply);

                    if ($reason === null) {
                        $found = $session;

                        break 2;
                    }

                    $blocked[$reason]++;
                }
            }

            if ($found === null) {
                foreach ($placed as $session) {
                    $this->releaseRoom($group, $session, $supply);
                }

                return null;
            }

            $placed[$index] = $found;
            $usedDays[$found['day']] = true;
        }

        ksort($placed);

        return array_values($placed);
    }

    /**
     * Lo que impide colocar las sesiones, o null si caben; si caben, ya quedaron apartados sus salones.
     *
     * @param  array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}  $group
     * @param  list<array{day: int, start: int, end: int, kind: SessionKind}>  $sessions
     * @param  array<int, list<array{int, int}>>  $taken
     */
    private function blocker(array $group, array $sessions, array $taken, int $blockMinutes, ?RoomSupply $supply): ?string
    {
        return match (true) {
            $this->overlapsTaken($sessions, $taken) => 'semestre',
            ! $this->departmentFits($group['department'], $sessions, $blockMinutes) => 'departamento',
            ! $this->reserveRooms($group, $sessions, $supply) => 'salón',
            default => null,
        };
    }

    /**
     * @param  array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}  $group
     * @param  list<array{day: int, start: int, end: int, kind: SessionKind}>  $sessions
     * @param  array<int, list<array{int, int}>>  $taken
     * @return list<array{day: int, start: int, end: int, kind: SessionKind}>
     */
    private function commit(array $group, array $sessions, array &$taken, int $blockMinutes): array
    {
        foreach ($sessions as $session) {
            $taken[$session['day']][] = [$session['start'], $session['end']];
            $this->loadDepartment($group['department'], $session, $blockMinutes);
        }

        return $sessions;
    }

    /**
     * @param  list<array{day: int, start: int, end: int, kind: SessionKind}>  $sessions
     * @param  array<int, list<array{int, int}>>  $taken
     */
    private function overlapsTaken(array $sessions, array $taken): bool
    {
        foreach ($sessions as $session) {
            foreach ($taken[$session['day']] ?? [] as [$from, $to]) {
                if (Minutes::overlap($session['start'], $session['end'], $from, $to)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{day: int, start: int, end: int, kind: SessionKind}>  $sessions
     */
    private function departmentFits(?string $department, array $sessions, int $blockMinutes): bool
    {
        if ($department === null || ! isset($this->departmentCaps[$department])) {
            return true;
        }

        foreach ($sessions as $session) {
            for ($block = $session['start']; $block < $session['end']; $block += $blockMinutes) {
                if (($this->departmentLoad[$department][$session['day']][$block] ?? 0) >= $this->departmentCaps[$department]) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array{day: int, start: int, end: int, kind: SessionKind}  $session
     */
    private function loadDepartment(?string $department, array $session, int $blockMinutes): void
    {
        if ($department === null) {
            return;
        }

        for ($block = $session['start']; $block < $session['end']; $block += $blockMinutes) {
            $this->departmentLoad[$department][$session['day']][$block] = ($this->departmentLoad[$department][$session['day']][$block] ?? 0) + 1;
        }
    }

    /**
     * Aparta un salón para cada sesión presencial; si a una no le queda, suelta lo apartado y responde que no.
     *
     * @param  array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}  $group
     * @param  list<array{day: int, start: int, end: int, kind: SessionKind}>  $sessions
     */
    private function reserveRooms(array $group, array $sessions, ?RoomSupply $supply): bool
    {
        if ($supply === null) {
            return true;
        }

        $reserved = [];

        foreach ($sessions as $index => $session) {
            if ($session['kind'] === SessionKind::Virtual) {
                continue;
            }

            $room = $supply->reserve($session['day'], $session['start'], $session['end'], $group['capacity'], $group['pieces'][$index]['practiceSpace'], $group['department']);

            if ($room === null) {
                foreach ($reserved as [$roomId, $day, $start, $end]) {
                    $supply->release($roomId, $day, $start, $end);
                }

                return false;
            }

            $reserved[] = [$room->id, $session['day'], $session['start'], $session['end']];
        }

        $this->reservations[$group['id']] = [...$this->reservations[$group['id']] ?? [], ...$reserved];

        return true;
    }

    /**
     * Suelta el salón que se apartó para una sesión del grupo.
     *
     * @param  array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}  $group
     * @param  array{day: int, start: int, end: int, kind: SessionKind}  $session
     */
    private function releaseRoom(array $group, array $session, ?RoomSupply $supply): void
    {
        foreach ($this->reservations[$group['id']] ?? [] as $key => [$roomId, $day, $start, $end]) {
            if ($day === $session['day'] && $start === $session['start'] && $end === $session['end']) {
                $supply?->release($roomId, $day, $start, $end);
                unset($this->reservations[$group['id']][$key]);

                return;
            }
        }
    }

    /**
     * Las horas de inicio, empezando en una distinta por plan y semestre, por materia y por opción, para repartir.
     * Primero las de cada dos bloques desde el inicio de la jornada (7:00, 9:00, 11:00…) y después las otras: así las
     * sesiones de dos horas no dejan huecos de una hora que nadie más puede usar en un salón o en un semestre.
     *
     * @param  list<int>  $starts
     * @return list<int>
     */
    private function rotate(array $starts, string $cohort, int $ordinal, int $variant): array
    {
        $order = [];

        foreach ([0, 1] as $parity) {
            $band = array_values(array_filter($starts, fn (int $index): bool => $index % 2 === $parity, ARRAY_FILTER_USE_KEY));
            $count = count($band);

            if ($count === 0) {
                continue;
            }

            $offset = (crc32($cohort) + $ordinal + $variant * intdiv($count, 2)) % $count;
            array_push($order, ...array_slice($band, $offset), ...array_slice($band, 0, $offset));
        }

        return $order;
    }

    /**
     * Combinaciones de `$count` días distintos y separados, empezando en una distinta por materia.
     *
     * @param  list<int>  $days
     * @return list<list<int>>
     */
    private function dayPatterns(int $count, array $days, int $ordinal): array
    {
        $size = count($days);

        if ($count > $size) {
            return [];
        }

        $step = max(1, intdiv($size, $count));
        $patterns = [];

        for ($from = 0; $from < $size; $from++) {
            $first = ($from + $ordinal) % $size;
            $pattern = [];

            for ($index = 0; $index < $count; $index++) {
                $pattern[] = $days[($first + $index * $step) % $size];
            }

            if (count(array_unique($pattern)) === $count) {
                sort($pattern);
                $patterns[implode(',', $pattern)] = $pattern;
            }
        }

        return array_values($patterns);
    }

    /**
     * Las horas en sesiones de a lo más `$pieceHours`, lo más parejas posible.
     *
     * @return list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>
     */
    private static function split(int $hours, SessionKind $kind, bool $practiceSpace, int $pieceHours = 2): array
    {
        $count = (int) ceil($hours / max(1, $pieceHours));
        $pieces = [];

        for ($index = 0; $index < $count; $index++) {
            $piece = (int) ceil($hours / ($count - $index));
            $pieces[] = ['minutes' => $piece * 60, 'kind' => $kind, 'practiceSpace' => $practiceSpace];
            $hours -= $piece;
        }

        return $pieces;
    }
}
