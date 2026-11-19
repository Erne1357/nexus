<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Core\Support\WeekTime;

/**
 * Cómo se lee en español lo que dice el diff de una versión publicada (SnapshotDiff, tarea 8.4): una línea por grupo
 * afectado, con su materia, su clave y su campus, y lo que le cambió. Es lo que dicen los avisos de la republicación
 * (RA-05: a cada docente, exactamente lo que cambió para él; RA-06) y lo pueden reutilizar la consulta pública y el tablero
 * de versiones. Los días y horas se leen como en el resto de Nexus (WeekTime: «lunes 7:00 a 9:00»).
 *
 * Un cambio de horario nombra solo las sesiones que cambiaron (ensayo 8.11, E-14), nunca todo el horario de antes y de
 * después: las que se movieron («de miércoles 9:00 a 10:00 en LC05 a jueves 9:00 a 10:00 en LN02»), las que solo
 * cambiaron de salón, las que se quitaron y las que se agregaron. Sale del diff guardado tal como está: con el horario y
 * el salón, las listas de `room` traen las sesiones con su salón; con solo el horario, las de `schedule`, sin salón.
 */
final class SnapshotDiffText
{
    /**
     * «SCD-1027 Tópicos Avanzados de Programación, grupo A (C2)», sin el campus si el grupo no tiene.
     *
     * @param  array<string, mixed>  $group
     */
    public static function group(array $group): string
    {
        return "{$group['subject']['code']} {$group['subject']['name']}, grupo {$group['code']}".($group['campus'] === null ? '' : " ({$group['campus']})");
    }

    /**
     * Una entrada de SnapshotDiff::teachers(), careers() o departments() en una línea, con punto final.
     *
     * @param  array{kind: string, group: array<string, mixed>, changes?: array<string, mixed>}  $entry
     */
    public static function entry(array $entry): string
    {
        $group = self::group($entry['group']);
        $schedule = $entry['group']['sessions'] === [] ? '' : ', '.self::sessions($entry['group']['sessions']);

        return match ($entry['kind']) {
            'added' => "Grupo nuevo: {$group}{$schedule}.",
            'assigned' => "Ahora impartes {$group}{$schedule}.",
            'cancelled' => "Se canceló {$group}.",
            'unassigned' => "Ya no impartes {$group}.",
            default => "{$group}: ".self::changes($entry['changes'] ?? []).'.',
        };
    }

    /**
     * Las líneas del diff completo para quien no es de la carga (la consulta publicada, 8.5), sin lo interno: grupos
     * nuevos, cancelados y cambiados, con horario, salón y docente; nunca cupos ni lugares por carrera (el público nunca
     * ve cupos ni lugares, reglas «Consulta publicada»), departamentos ni llaves. Un grupo cuyo único cambio fue de cupo o
     * de lugares por carrera no sale.
     *
     * @param  array<string, mixed>  $diff
     * @return list<string>
     */
    public static function publicEntries(array $diff): array
    {
        $entries = [];

        foreach ($diff['groups']['added'] as $group) {
            $entries[] = self::entry(['kind' => 'added', 'group' => $group]);
        }

        foreach ($diff['groups']['cancelled'] as $group) {
            $entries[] = self::entry(['kind' => 'cancelled', 'group' => $group]);
        }

        foreach ($diff['groups']['changed'] as $changed) {
            $changes = array_diff_key($changed['changes'], ['quota' => true, 'career_quotas' => true]);

            if ($changes !== []) {
                $entries[] = self::entry(['kind' => 'changed', 'group' => $changed['group'], 'changes' => $changes]);
            }
        }

        return $entries;
    }

    /**
     * Los cambios de un grupo que sigue, separados por «; »: las sesiones que cambiaron de horario (con su salón si también
     * cambió), salón de cada sesión que lo cambió, docente, cupo y lugares por carrera.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function changes(array $changes): string
    {
        $parts = [];

        if (isset($changes['schedule'], $changes['room'])) {
            $parts[] = self::scheduleChanges($changes['room']['from'], $changes['room']['to']);
        } elseif (isset($changes['schedule'])) {
            $parts[] = self::scheduleChanges($changes['schedule']['from'], $changes['schedule']['to']);
        } elseif (isset($changes['room'])) {
            $parts[] = 'salón '.self::roomChanges($changes['room']['from'], $changes['room']['to']);
        }

        if (array_key_exists('teacher', $changes)) {
            $parts[] = 'docente de '.($changes['teacher']['from']['name'] ?? 'Por asignar').' a '.($changes['teacher']['to']['name'] ?? 'Por asignar');
        }

        if (isset($changes['quota'])) {
            $parts[] = "cupo de {$changes['quota']['from']} a {$changes['quota']['to']}";
        }

        if (isset($changes['career_quotas'])) {
            $parts[] = 'lugares por carrera de '.self::seats($changes['career_quotas']['from']).' a '.self::seats($changes['career_quotas']['to']);
        }

        return implode('; ', $parts);
    }

    /**
     * «lunes 7:00 a 9:00 en A-101 y miércoles 7:00 a 10:00 sin salón»; sin la llave `room`, solo días y horas; sin
     * sesiones, «ninguno».
     *
     * @param  list<array<string, mixed>>  $sessions
     */
    public static function sessions(array $sessions): string
    {
        if ($sessions === []) {
            return 'ninguno';
        }

        return self::join(array_map(self::session(...), $sessions));
    }

    /**
     * Lo que cambió de un horario, sesión por sesión, separado por «, »: las que se movieron («horario de miércoles 9:00
     * a 10:00 a jueves 9:00 a 10:00», «horario y salón de …» si alguna movida también cambió de salón), las que con las
     * mismas horas solo cambiaron de salón («salón del lunes 7:00 a 9:00 de A-101 a A-204»), las que se quitaron («se
     * quita la sesión del …») y las que se agregaron («se agrega la sesión del …»). Una sesión que salió y otra que entró
     * son la misma movida: se emparejan primero las de mismas horas, luego las de la misma duración (del mismo día y con la
     * misma hora de inicio, mejor) y al final en orden; las que sobran se quitaron o se agregaron.
     *
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     */
    private static function scheduleChanges(array $from, array $to): string
    {
        [$removed, $added] = self::difference($from, $to);
        [$moves, $removed, $added] = self::pair($removed, $added);
        $retimed = array_values(array_filter($moves, fn (array $move): bool => ! self::sameTime($move[0], $move[1])));
        $rehoused = array_values(array_filter($moves, fn (array $move): bool => self::sameTime($move[0], $move[1])));
        $parts = [];

        if ($retimed !== []) {
            $withRoom = array_any($retimed, fn (array $move): bool => ($move[0]['room'] ?? null) !== ($move[1]['room'] ?? null));
            $parts[] = ($withRoom ? 'horario y salón ' : 'horario ').self::join(array_map(fn (array $move): string => 'de '.self::session($move[0]).' a '.self::session($move[1]), $retimed));
        }

        if ($rehoused !== []) {
            $parts[] = 'salón '.self::join(array_map(fn (array $move): string => 'del '.self::time($move[1]).' de '.($move[0]['room'] ?? 'ninguno').' a '.($move[1]['room'] ?? 'ninguno'), $rehoused));
        }

        if ($removed !== []) {
            $parts[] = (count($removed) === 1 ? 'se quita la sesión ' : 'se quitan las sesiones ').self::join(array_map(fn (array $session): string => 'del '.self::session($session), $removed));
        }

        if ($added !== []) {
            $parts[] = (count($added) === 1 ? 'se agrega la sesión ' : 'se agregan las sesiones ').self::join(array_map(fn (array $session): string => 'del '.self::session($session), $added));
        }

        return $parts === [] ? 'horario de '.self::sessions($from).' a '.self::sessions($to) : implode(', ', $parts);
    }

    /**
     * Las sesiones que están en una lista y no en la otra (por día, horas y, si lo traen, salón), en su orden.
     *
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function difference(array $from, array $to): array
    {
        $key = fn (array $session): string => implode('|', [$session['day'], $session['starts_at'], $session['ends_at'], array_key_exists('room', $session) ? ($session['room'] ?? '-') : '']);
        $removed = [];

        foreach ($from as $session) {
            $found = array_find_key($to, fn (array $other): bool => $key($other) === $key($session));

            if ($found === null) {
                $removed[] = $session;
            } else {
                unset($to[$found]);
            }
        }

        return [$removed, array_values($to)];
    }

    /**
     * Empareja las sesiones que salieron con las que entraron (ver scheduleChanges()): las movidas, en el orden de las que
     * salieron, y las que sobran de cada lado.
     *
     * @param  list<array<string, mixed>>  $removed
     * @param  list<array<string, mixed>>  $added
     * @return array{0: list<array{0: array<string, mixed>, 1: array<string, mixed>}>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private static function pair(array $removed, array $added): array
    {
        $candidates = [];

        foreach ($removed as $i => $before) {
            foreach ($added as $j => $after) {
                $candidates[] = [
                    (self::sameTime($before, $after) ? 8 : 0) + (self::minutes($before) === self::minutes($after) ? 4 : 0)
                        + ($before['day'] === $after['day'] ? 2 : 0) + ($before['starts_at'] === $after['starts_at'] ? 1 : 0),
                    $i,
                    $j,
                ];
            }
        }

        usort($candidates, fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $moves = [];

        foreach ($candidates as [, $i, $j]) {
            if (isset($removed[$i], $added[$j])) {
                $moves[$i] = [$removed[$i], $added[$j]];
                unset($removed[$i], $added[$j]);
            }
        }

        ksort($moves);

        return [array_values($moves), array_values($removed), array_values($added)];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameTime(array $a, array $b): bool
    {
        return $a['day'] === $b['day'] && $a['starts_at'] === $b['starts_at'] && $a['ends_at'] === $b['ends_at'];
    }

    /**
     * Cuántos minutos dura una sesión.
     *
     * @param  array<string, mixed>  $session
     */
    private static function minutes(array $session): int
    {
        $clock = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);

        return $clock($session['ends_at']) - $clock($session['starts_at']);
    }

    /**
     * Una sesión con su salón, si lo trae: «lunes 7:00 a 9:00 en A-101».
     *
     * @param  array<string, mixed>  $session
     */
    private static function session(array $session): string
    {
        return self::time($session).(array_key_exists('room', $session) ? ' '.self::room($session['room']) : '');
    }

    /**
     * Con el mismo horario, solo las sesiones que cambiaron de salón: «del miércoles 7:00 a 9:00 de A-102 a A-204»; una
     * sesión sin salón, «ninguno».
     *
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     */
    private static function roomChanges(array $from, array $to): string
    {
        $changes = [];

        foreach ($to as $index => $session) {
            $before = $from[$index]['room'] ?? null;

            if ($before !== $session['room']) {
                $changes[] = 'del '.self::time($session).' de '.($before ?? 'ninguno').' a '.($session['room'] ?? 'ninguno');
            }
        }

        return self::join($changes);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private static function time(array $session): string
    {
        return WeekTime::day($session['day']).' '.WeekTime::clock($session['starts_at']).' a '.WeekTime::clock($session['ends_at']);
    }

    private static function room(?string $room): string
    {
        return $room === null ? 'sin salón' : "en {$room}";
    }

    /**
     * @param  list<array{code: string, seats: int}>  $seats
     */
    private static function seats(array $seats): string
    {
        return $seats === [] ? 'ninguna' : implode(', ', array_map(fn (array $career): string => "{$career['code']} {$career['seats']}", $seats));
    }

    /**
     * «a», «a y b», «a, b y c».
     *
     * @param  list<string>  $parts
     */
    private static function join(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' y '.$last;
    }
}
