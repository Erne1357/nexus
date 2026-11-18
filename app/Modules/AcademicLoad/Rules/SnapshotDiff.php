<?php

namespace App\Modules\AcademicLoad\Rules;

/**
 * Lo que cambió una versión publicada de una carga contra la anterior (tarea 8.4): se calcula al publicar la versión
 * n + 1 y se guarda en `load_snapshots.diff` (nulo en la versión 1), así que es lo que leen los avisos de la
 * republicación (RA-05, RA-06), la consulta pública y los exportes con versión (8.5) y el tablero de versiones (8.10).
 * Es pura: recibe los dos payloads ya normalizados (SnapshotPayload::normalize) y el departamento de cada carrera, que
 * el payload no trae (lo lee de Academic quien llama).
 *
 * Contrato del diff (v1), documentado también en docs/plan/fase-8-reanalisis.md (tarea 8.4):
 *
 * ```
 * {
 *   "version": 1,
 *   "groups": {
 *     "added":     [Grupo, ...],
 *     "cancelled": [Grupo, ...],
 *     "changed":   [{"group": Grupo, "changes": {
 *         "schedule"?:      {"from": [Sesión sin salón], "to": [...]},
 *         "teacher"?:       {"from": {"id", "name"} | null, "to": {"id", "name"} | null},
 *         "room"?:          {"from": [Sesión con salón], "to": [...]},
 *         "quota"?:         {"from": int, "to": int},
 *         "career_quotas"?: {"from": [{"id", "code", "seats"}], "to": [...]}
 *     }}, ...]
 *   },
 *   "summary": {"added", "cancelled", "changed", "teachers_affected", "careers_affected", "departments_affected"}
 * }
 *
 * Grupo = {
 *   "id": uuid (groups[].id del payload: la identidad del grupo entre versiones),
 *   "code": "A", "subject": {"id", "code", "name"}, "campus": "C2" | null,
 *   "teacher": {"id", "name"} | null,
 *   "careers": [{"id", "code", "department_id" | null, "is_owner"}],  // con lugares en alguna de las dos versiones, la dueña primero
 *   "sessions": [Sesión con salón],
 *   "other_departments": [uuid]                              // otros departamentos que toca este cambio
 * }
 * Sesión sin salón = {"day": 1..6, "starts_at": "HH:MM", "ends_at": "HH:MM"}; con salón, además "room": clave | null.
 * ```
 *
 * - Un grupo se identifica por `groups[].id`. Nuevo: está solo en la versión nueva; cancelado: solo en la anterior. El
 *   Grupo de un nuevo o de un cambiado dice cómo quedó; el de un cancelado, lo que tenía.
 * - `schedule`: cambiaron los días u horas de sus sesiones. `room`: cambió el salón de alguna sesión (con el mismo horario,
 *   sesión por sesión; si el horario también cambió, si cambió el conjunto de salones). `teacher`: otro docente o ninguno.
 *   `quota`: la capacidad del grupo. `career_quotas`: los lugares de cada carrera (una vez por carrera, aunque se repitan
 *   en cada retícula). El tipo de sesión, la justificación y los nombres no cuentan como cambio.
 * - `other_departments`: los dueños de un salón prestado (`room.owner_department_id` distinto del departamento de la
 *   carga) en una sesión que entró, salió o se movió con este cambio, y los departamentos de las carreras de otro
 *   departamento con lugares compartidos en el grupo (no la dueña: el grupo compartido es el cupo de otra carrera,
 *   «Acuerdos entre departamentos»; la carrera dueña suele ser de otro departamento, como ISC en un grupo de Ciencias
 *   Básicas, y su coordinador ya recibe el aviso) cuando el grupo se crea, se cancela o cambia en cualquier cosa: «se
 *   tocan grupos compartidos» (RA-05; interpretación por confirmar). Una carrera cuyo departamento no se conoce no
 *   cuenta. `is_owner` es el de la versión que primero la nombra (la nueva, si está en las dos).
 *
 * De ahí salen los afectados de RA-05: teachers() (cada docente con exactamente lo que cambió para él), careers() (cada
 * carrera, para sus coordinadores) y departments() (cada otro departamento, para su jefe). El resumen los cuenta.
 */
final class SnapshotDiff
{
    public const int VERSION = 1;

    /**
     * @param  array<string, mixed>  $before  payload normalizado de la versión n
     * @param  array<string, mixed>  $after  payload normalizado de la versión n + 1
     * @param  array<string, string|null>  $careerDepartments  el departamento de cada carrera, por id
     * @return array{version: int, groups: array{added: list<array<string, mixed>>, cancelled: list<array<string, mixed>>, changed: list<array{group: array<string, mixed>, changes: array<string, mixed>}>}, summary: array{added: int, cancelled: int, changed: int, teachers_affected: int, careers_affected: int, departments_affected: int}}
     */
    public static function between(array $before, array $after, array $careerDepartments = []): array
    {
        $department = (string) $after['department']['id'];
        $old = self::keyed($before['groups']);
        $new = self::keyed($after['groups']);
        $groups = ['added' => [], 'cancelled' => [], 'changed' => []];

        foreach ($new as $id => $group) {
            if (! isset($old[$id])) {
                $groups['added'][] = self::group($group, [$group], $group['sessions'], $department, $careerDepartments);

                continue;
            }

            $changes = self::changes($old[$id], $group);

            if ($changes !== []) {
                $touched = self::touchedSessions($old[$id]['sessions'], $group['sessions']);
                $groups['changed'][] = ['group' => self::group($group, [$group, $old[$id]], $touched, $department, $careerDepartments), 'changes' => $changes];
            }
        }

        foreach ($old as $id => $group) {
            if (! isset($new[$id])) {
                $groups['cancelled'][] = self::group($group, [$group], $group['sessions'], $department, $careerDepartments);
            }
        }

        $diff = ['version' => self::VERSION, 'groups' => $groups];

        return $diff + ['summary' => [
            'added' => count($groups['added']),
            'cancelled' => count($groups['cancelled']),
            'changed' => count($groups['changed']),
            'teachers_affected' => count(self::teachers($diff)),
            'careers_affected' => count(self::careers($diff)),
            'departments_affected' => count(self::departments($diff)),
        ]];
    }

    /**
     * Si la versión nueva no cambió nada contra la anterior.
     *
     * @param  array<string, mixed>  $diff
     */
    public static function isEmpty(array $diff): bool
    {
        return $diff['groups']['added'] === [] && $diff['groups']['cancelled'] === [] && $diff['groups']['changed'] === [];
    }

    /**
     * Los docentes afectados (RA-05), cada uno con lo que cambió para él, por id del docente: `added` (un grupo nuevo
     * suyo), `cancelled` (se canceló un grupo suyo), `assigned` (un grupo que ya existía ahora es suyo), `unassigned` (un
     * grupo dejó de ser suyo) y `changed` (un grupo que sigue siendo suyo cambió; lleva sus `changes`).
     *
     * @param  array<string, mixed>  $diff
     * @return array<string, array{teacher: array{id: string, name: string}, entries: list<array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}>}>
     */
    public static function teachers(array $diff): array
    {
        $teachers = [];
        $add = function (?array $teacher, string $kind, array $group, ?array $changes = null) use (&$teachers): void {
            if ($teacher === null) {
                return;
            }

            $teachers[$teacher['id']] ??= ['teacher' => $teacher, 'entries' => []];
            $teachers[$teacher['id']]['entries'][] = ['kind' => $kind, 'group' => $group, ...($changes === null ? [] : ['changes' => $changes])];
        };

        foreach ($diff['groups']['added'] as $group) {
            $add($group['teacher'], 'added', $group);
        }

        foreach ($diff['groups']['cancelled'] as $group) {
            $add($group['teacher'], 'cancelled', $group);
        }

        foreach ($diff['groups']['changed'] as $entry) {
            if (! array_key_exists('teacher', $entry['changes'])) {
                $add($entry['group']['teacher'], 'changed', $entry['group'], $entry['changes']);

                continue;
            }

            $add($entry['changes']['teacher']['from'], 'unassigned', $entry['group']);
            $add($entry['changes']['teacher']['to'], 'assigned', $entry['group']);
        }

        return $teachers;
    }

    /**
     * Las carreras afectadas (RA-05, para sus coordinadores), por id: las que tienen lugares, en alguna de las dos
     * versiones, en un grupo nuevo, cancelado o cambiado, cada una con esos grupos (`kind`: added, cancelled o changed).
     *
     * @param  array<string, mixed>  $diff
     * @return array<string, array{career: array{id: string, code: string}, entries: list<array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}>}>
     */
    public static function careers(array $diff): array
    {
        $careers = [];

        foreach (self::entries($diff) as $entry) {
            foreach ($entry['group']['careers'] as $career) {
                $careers[$career['id']] ??= ['career' => ['id' => $career['id'], 'code' => $career['code']], 'entries' => []];
                $careers[$career['id']]['entries'][] = $entry;
            }
        }

        return $careers;
    }

    /**
     * Los otros departamentos afectados (RA-05, para sus jefes), por id: los de `other_departments` de cada grupo nuevo,
     * cancelado o cambiado, cada uno con esos grupos.
     *
     * @param  array<string, mixed>  $diff
     * @return array<string, array{entries: list<array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}>}>
     */
    public static function departments(array $diff): array
    {
        $departments = [];

        foreach (self::entries($diff) as $entry) {
            foreach ($entry['group']['other_departments'] as $department) {
                $departments[$department]['entries'][] = $entry;
            }
        }

        return $departments;
    }

    /**
     * Las carreras con lugares en los grupos de los payloads, cada una una vez, para buscar su departamento.
     *
     * @param  array<string, mixed>  ...$payloads
     * @return list<string>
     */
    public static function careerIds(array ...$payloads): array
    {
        $ids = [];

        foreach ($payloads as $payload) {
            foreach ($payload['groups'] as $group) {
                foreach ($group['placements'] as $placement) {
                    $ids[$placement['career']['id']] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * Cada grupo nuevo, cancelado o cambiado con su clase, en ese orden.
     *
     * @param  array<string, mixed>  $diff
     * @return list<array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}>
     */
    private static function entries(array $diff): array
    {
        return [
            ...array_map(fn (array $group): array => ['kind' => 'added', 'group' => $group], $diff['groups']['added']),
            ...array_map(fn (array $group): array => ['kind' => 'cancelled', 'group' => $group], $diff['groups']['cancelled']),
            ...array_map(fn (array $entry): array => ['kind' => 'changed', 'group' => $entry['group'], 'changes' => $entry['changes']], $diff['groups']['changed']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(array $groups): array
    {
        $keyed = [];

        foreach ($groups as $group) {
            $keyed[$group['id']] = $group;
        }

        return $keyed;
    }

    /**
     * El grupo como lo lleva el diff. `$versions` son el grupo en las versiones que cuentan (la nueva primero), de donde
     * salen sus carreras; `$touched`, las sesiones que entraron, salieron o se movieron, de donde salen los préstamos.
     *
     * @param  array<string, mixed>  $group
     * @param  list<array<string, mixed>>  $versions
     * @param  list<array<string, mixed>>  $touched
     * @param  array<string, string|null>  $careerDepartments
     * @return array<string, mixed>
     */
    private static function group(array $group, array $versions, array $touched, string $department, array $careerDepartments): array
    {
        $careers = [];

        foreach ($versions as $version) {
            foreach ($version['placements'] as $placement) {
                $id = $placement['career']['id'];
                $careers[$id] ??= ['id' => $id, 'code' => $placement['career']['code'], 'department_id' => $careerDepartments[$id] ?? null, 'is_owner' => $placement['is_owner']];
            }
        }

        $others = [];

        foreach ($touched as $session) {
            $owner = $session['room']['owner_department_id'] ?? null;

            if ($owner !== null && $owner !== $department) {
                $others[$owner] = true;
            }
        }

        foreach ($careers as $career) {
            if (! $career['is_owner'] && $career['department_id'] !== null && $career['department_id'] !== $department) {
                $others[$career['department_id']] = true;
            }
        }

        $others = array_map('strval', array_keys($others));
        sort($others);

        return [
            'id' => $group['id'],
            'code' => $group['code'],
            'subject' => ['id' => $group['subject']['id'], 'code' => $group['subject']['code'], 'name' => $group['subject']['name']],
            'campus' => $group['campus']['code'] ?? null,
            'teacher' => self::teacher($group['teacher']),
            'careers' => array_values($careers),
            'sessions' => self::sessions($group['sessions'], withRooms: true),
            'other_departments' => $others,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    private static function changes(array $before, array $after): array
    {
        $changes = [];
        $from = self::sessions($before['sessions'], withRooms: false);
        $to = self::sessions($after['sessions'], withRooms: false);

        if ($from !== $to) {
            $changes['schedule'] = ['from' => $from, 'to' => $to];
        }

        if (($before['teacher']['id'] ?? null) !== ($after['teacher']['id'] ?? null)) {
            $changes['teacher'] = ['from' => self::teacher($before['teacher']), 'to' => self::teacher($after['teacher'])];
        }

        if (self::roomsDiffer($before['sessions'], $after['sessions'], sameSchedule: $from === $to)) {
            $changes['room'] = ['from' => self::sessions($before['sessions'], withRooms: true), 'to' => self::sessions($after['sessions'], withRooms: true)];
        }

        if ($before['capacity'] !== $after['capacity']) {
            $changes['quota'] = ['from' => $before['capacity'], 'to' => $after['capacity']];
        }

        $seatsBefore = self::seats($before);
        $seatsAfter = self::seats($after);

        if (self::seatMap($seatsBefore) !== self::seatMap($seatsAfter)) {
            $changes['career_quotas'] = ['from' => $seatsBefore, 'to' => $seatsAfter];
        }

        return $changes;
    }

    /**
     * Con el mismo horario, sesión por sesión; si el horario cambió, el conjunto de salones.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    private static function roomsDiffer(array $before, array $after, bool $sameSchedule): bool
    {
        $rooms = fn (array $sessions): array => array_map(fn (array $session): string => (string) ($session['room']['id'] ?? ''), self::ordered($sessions));
        [$from, $to] = [$rooms($before), $rooms($after)];

        if (! $sameSchedule) {
            sort($from);
            sort($to);
        }

        return $from !== $to;
    }

    /**
     * Las sesiones que están en una versión y no en la otra (por día, horas y salón).
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array<string, mixed>>
     */
    private static function touchedSessions(array $before, array $after): array
    {
        $key = fn (array $session): string => implode('|', [$session['day'], $session['starts_at'], $session['ends_at'], $session['room']['id'] ?? '']);
        $beforeKeys = array_map($key, $before);
        $afterKeys = array_map($key, $after);

        return [
            ...array_filter($before, fn (array $session): bool => ! in_array($key($session), $afterKeys, true)),
            ...array_filter($after, fn (array $session): bool => ! in_array($key($session), $beforeKeys, true)),
        ];
    }

    /**
     * Las sesiones por día y hora, con la clave de su salón si se pide.
     *
     * @param  list<array<string, mixed>>  $sessions
     * @return list<array<string, mixed>>
     */
    private static function sessions(array $sessions, bool $withRooms): array
    {
        return array_map(fn (array $session): array => [
            'day' => $session['day'],
            'starts_at' => $session['starts_at'],
            'ends_at' => $session['ends_at'],
            ...($withRooms ? ['room' => $session['room']['code'] ?? null] : []),
        ], self::ordered($sessions));
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $sessions): array
    {
        usort($sessions, fn (array $a, array $b): int => [$a['day'], $a['starts_at'], $a['ends_at']] <=> [$b['day'], $b['starts_at'], $b['ends_at']]);

        return $sessions;
    }

    /**
     * Los lugares de cada carrera, una vez por carrera, en el orden del payload (la dueña primero).
     *
     * @param  array<string, mixed>  $group
     * @return list<array{id: string, code: string, seats: int}>
     */
    private static function seats(array $group): array
    {
        $seats = [];

        foreach ($group['placements'] as $placement) {
            $seats[$placement['career']['id']] ??= ['id' => $placement['career']['id'], 'code' => $placement['career']['code'], 'seats' => $placement['seats']];
        }

        return array_values($seats);
    }

    /**
     * @param  list<array{id: string, code: string, seats: int}>  $seats
     * @return array<string, int>
     */
    private static function seatMap(array $seats): array
    {
        $map = array_column($seats, 'seats', 'id');
        ksort($map);

        return $map;
    }

    /**
     * @param  array<string, mixed>|null  $teacher
     * @return array{id: string, name: string}|null
     */
    private static function teacher(?array $teacher): ?array
    {
        return $teacher === null ? null : ['id' => $teacher['id'], 'name' => $teacher['name']];
    }
}
