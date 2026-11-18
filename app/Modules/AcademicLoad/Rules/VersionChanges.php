<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Core\Support\WeekTime;
use Illuminate\Support\Str;

/**
 * Lo que cambió una versión publicada (el diff de SnapshotDiff, tarea 8.4) como lo lee el tablero de versiones (tarea
 * 8.10; DESIGN.md «Versiones publicadas», maqueta C «Línea y detalle»):
 *
 * - por tipo (byKind()): nuevos, cancelados, horario, docente, salón, cupo y cupos por carrera, cada grupo con lo que
 *   tenía y lo que quedó en pocas palabras («L Mi 7:00–8:00 en D-14»). Un grupo con dos cambios sale en los dos tipos;
 * - por docente y por carrera (byTeacher(), byCareer()): cada uno con las mismas líneas de SnapshotDiffText que le
 *   dijeron los avisos de la republicación (RA-05, RA-06).
 *
 * Es pura: recibe el diff ya leído (LoadVersionsQuery::diff()) y, para las carreras, sus nombres.
 */
final class VersionChanges
{
    /** Los tipos de cambio de un grupo que sigue, en el orden del tablero: nombre e icono. */
    public const array KINDS = [
        'schedule' => ['Horario', 'o-clock'],
        'teacher' => ['Docente', 'o-user'],
        'room' => ['Salón', 'o-map-pin'],
        'quota' => ['Cupo', 'o-users'],
        'career_quotas' => ['Cupos por carrera', 'o-academic-cap'],
    ];

    /** Los días como en la consulta publicada (PublishedWeek). */
    private const array DAYS = [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

    /**
     * Las secciones del diff por tipo, en el orden del tablero, sin las vacías. Cada renglón lleva su grupo y, si es nuevo
     * o cancelado, su detalle (horario, docente y carreras); si cambió, lo que tenía (`from`) y lo que quedó (`to`).
     *
     * @param  array<string, mixed>  $diff
     * @return list<array{kind: string, label: string, icon: string, rows: list<array{group: array{code: string, subject_code: string, subject_name: string, campus: ?string}, detail: ?string, from: ?string, to: ?string}>}>
     */
    public static function byKind(array $diff): array
    {
        $sections = [
            self::section('added', 'Nuevos', 'o-plus-circle', array_map(fn (array $group): array => self::row($group, detail: self::describe($group)), $diff['groups']['added'])),
            self::section('cancelled', 'Cancelados', 'o-minus-circle', array_map(fn (array $group): array => self::row($group, detail: 'Tenía '.self::describe($group)), $diff['groups']['cancelled'])),
        ];

        foreach (self::KINDS as $kind => [$label, $icon]) {
            $rows = [];

            foreach ($diff['groups']['changed'] as $entry) {
                if (array_key_exists($kind, $entry['changes'])) {
                    [$from, $to] = self::fromTo($kind, $entry['changes']);
                    $rows[] = self::row($entry['group'], from: $from, to: $to);
                }
            }

            $sections[] = self::section($kind, $label, $icon, $rows);
        }

        return array_values(array_filter($sections, fn (array $section): bool => $section['rows'] !== []));
    }

    /**
     * Cada docente afectado (SnapshotDiff::teachers()), por nombre, con las líneas que le dijo el aviso.
     *
     * @param  array<string, mixed>  $diff
     * @return list<array{name: string, lines: list<string>}>
     */
    public static function byTeacher(array $diff): array
    {
        $teachers = array_map(fn (array $teacher): array => [
            'name' => $teacher['teacher']['name'],
            'lines' => array_map(SnapshotDiffText::entry(...), $teacher['entries']),
        ], array_values(SnapshotDiff::teachers($diff)));

        usort($teachers, fn (array $a, array $b): int => strcmp(self::fold($a['name']), self::fold($b['name'])));

        return $teachers;
    }

    /**
     * Cada carrera afectada (SnapshotDiff::careers(), para sus coordinadores), por clave, con su nombre si se conoce y las
     * líneas de sus grupos.
     *
     * @param  array<string, mixed>  $diff
     * @param  array<string, string>  $names  el nombre de cada carrera, por id
     * @param  array<string, mixed>|null  $careers  SnapshotDiff::careers($diff) si el llamador ya lo tiene
     * @return list<array{code: string, name: ?string, lines: list<string>}>
     */
    public static function byCareer(array $diff, array $names = [], ?array $careers = null): array
    {
        $careers = array_map(fn (array $career): array => [
            'code' => $career['career']['code'],
            'name' => $names[$career['career']['id']] ?? null,
            'lines' => array_map(SnapshotDiffText::entry(...), $career['entries']),
        ], array_values($careers ?? SnapshotDiff::careers($diff)));

        usort($careers, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $careers;
    }

    /**
     * Las sesiones en pocas palabras: las de mismas horas (y mismo salón, si lo traen) juntan sus días, «L Mi 7:00–8:00
     * en D-14 · M 7:00–9:00 sin salón»; sin sesiones, «sin horario».
     *
     * @param  list<array<string, mixed>>  $sessions
     */
    public static function sessions(array $sessions): string
    {
        if ($sessions === []) {
            return 'sin horario';
        }

        $merged = [];

        foreach ($sessions as $session) {
            $withRoom = array_key_exists('room', $session);
            $key = implode('|', [$session['starts_at'], $session['ends_at'], $withRoom ? ($session['room'] ?? '-') : '']);
            $merged[$key] ??= ['session' => $session, 'days' => []];
            $merged[$key]['days'][] = $session['day'];
        }

        return implode(' · ', array_map(function (array $entry): string {
            $session = $entry['session'];
            $room = array_key_exists('room', $session) ? ' '.($session['room'] === null ? 'sin salón' : "en {$session['room']}") : '';

            return self::days($entry['days']).' '.self::range($session).$room;
        }, array_values($merged)));
    }

    /**
     * @param  list<array{group: array<string, mixed>, detail: ?string, from: ?string, to: ?string}>  $rows
     * @return array{kind: string, label: string, icon: string, rows: list<array{group: array{code: string, subject_code: string, subject_name: string, campus: ?string}, detail: ?string, from: ?string, to: ?string}>}
     */
    private static function section(string $kind, string $label, string $icon, array $rows): array
    {
        return ['kind' => $kind, 'label' => $label, 'icon' => $icon, 'rows' => $rows];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{group: array{code: string, subject_code: string, subject_name: string, campus: ?string}, detail: ?string, from: ?string, to: ?string}
     */
    private static function row(array $group, ?string $detail = null, ?string $from = null, ?string $to = null): array
    {
        return [
            'group' => ['code' => $group['code'], 'subject_code' => $group['subject']['code'], 'subject_name' => $group['subject']['name'], 'campus' => $group['campus']],
            'detail' => $detail,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Un grupo nuevo o cancelado: su horario con salones, su docente y sus carreras.
     *
     * @param  array<string, mixed>  $group
     */
    private static function describe(array $group): string
    {
        return implode(' · ', array_filter([
            self::sessions($group['sessions']),
            $group['teacher']['name'] ?? 'Por asignar',
            implode(', ', array_column($group['careers'], 'code')),
        ], fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{0: string, 1: string}
     */
    private static function fromTo(string $kind, array $changes): array
    {
        $change = $changes[$kind];

        return match ($kind) {
            'schedule' => [self::sessions($change['from']), self::sessions($change['to'])],
            'teacher' => [$change['from']['name'] ?? 'Por asignar', $change['to']['name'] ?? 'Por asignar'],
            'room' => isset($changes['schedule']) ? [self::sessions($change['from']), self::sessions($change['to'])] : self::roomMoves($change['from'], $change['to']),
            'quota' => [(string) $change['from'], (string) $change['to']],
            default => [self::seats($change['from']), self::seats($change['to'])],
        };
    }

    /**
     * Con el mismo horario, solo las sesiones que cambiaron de salón, juntas por salón de antes y de ahora: «D-14 (las 3
     * sesiones)» a «E-05», o «A-102 (Mi 7:00–9:00)» a «A-204».
     *
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return array{0: string, 1: string}
     */
    private static function roomMoves(array $from, array $to): array
    {
        $moves = [];

        foreach ($to as $index => $session) {
            $before = $from[$index]['room'] ?? null;

            if ($before !== $session['room']) {
                $key = ($before ?? '').'|'.($session['room'] ?? '');
                $moves[$key] ??= ['from' => $before, 'to' => $session['room'], 'sessions' => []];
                $moves[$key]['sessions'][] = array_diff_key($session, ['room' => true]);
            }
        }

        // Cambió el salón de alguna sesión aunque con la misma clave (otro salón con ese nombre): se dicen las sesiones.
        if ($moves === []) {
            return [self::sessions($from), self::sessions($to)];
        }

        $room = fn (?string $code): string => $code ?? 'sin salón';
        $where = fn (array $sessions): string => count($sessions) === count($to) && count($to) > 1 ? 'las '.count($to).' sesiones' : self::sessions($sessions);

        return [
            implode(' · ', array_map(fn (array $move): string => $room($move['from']).' ('.$where($move['sessions']).')', $moves)),
            implode(' · ', array_map(fn (array $move): string => $room($move['to']), $moves)),
        ];
    }

    /**
     * @param  list<array{code: string, seats: int}>  $seats
     */
    private static function seats(array $seats): string
    {
        return $seats === [] ? 'ninguna' : implode(' · ', array_map(fn (array $career): string => "{$career['code']} {$career['seats']}", $seats));
    }

    /**
     * @param  list<int>  $days
     */
    private static function days(array $days): string
    {
        return implode(' ', array_map(fn (int $day): string => self::DAYS[$day] ?? (string) $day, $days));
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private static function range(array $session): string
    {
        return WeekTime::clock($session['starts_at']).'–'.WeekTime::clock($session['ends_at']);
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }
}
