<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Core\Support\WeekTime;

/**
 * Cómo se lee en español lo que dice el diff de una versión publicada (SnapshotDiff, tarea 8.4): una línea por grupo
 * afectado, con su materia, su clave y su campus, y lo que le cambió. Es lo que dicen los avisos de la republicación
 * (RA-05: a cada docente, exactamente lo que cambió para él; RA-06) y lo pueden reutilizar la consulta pública y el tablero
 * de versiones. Los días y horas se leen como en el resto de Nexus (WeekTime: «lunes 7:00 a 9:00»).
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
     * Los cambios de un grupo que sigue, separados por «; »: horario (con los salones si también cambiaron), salón de cada
     * sesión que lo cambió, docente, cupo y lugares por carrera.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function changes(array $changes): string
    {
        $parts = [];

        if (isset($changes['schedule'], $changes['room'])) {
            $parts[] = 'horario y salón de '.self::sessions($changes['room']['from']).' a '.self::sessions($changes['room']['to']);
        } elseif (isset($changes['schedule'])) {
            $parts[] = 'horario de '.self::sessions($changes['schedule']['from']).' a '.self::sessions($changes['schedule']['to']);
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

        return self::join(array_map(fn (array $session): string => self::time($session).(array_key_exists('room', $session) ? ' '.self::room($session['room']) : ''), $sessions));
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
