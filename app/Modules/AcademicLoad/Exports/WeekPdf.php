<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedWeek;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\WeekGrid;
use App\Modules\Core\Support\WeekTime;

/**
 * El PDF de una semana (tarea 4.5): el de un docente o el de un salón, que eligió el usuario el 10 de noviembre de 2026
 * para que quepa en una sola hoja. Una tabla con una columna por día y un renglón por bloque del perfil de la modalidad
 * (WeekGrid), donde cada sesión es una celda que abarca sus bloques (`rowspan`), con el color de su materia. Un
 * docente o un salón no tienen dos sesiones a la vez en un campus (HC-01, HC-02); si llegaran a tenerlas, la que
 * empieza dentro de otra se lista en la misma celda, que se alarga hasta la última.
 */
abstract class WeekPdf extends PublishedPdf
{
    /**
     * @return list<PublishedGroup>
     */
    abstract protected function groupsOf(PublishedSchedule $schedule): array;

    abstract protected function title(): string;

    abstract protected function subtitle(PublishedSchedule $schedule): string;

    /**
     * La línea de detalle de un bloque: el salón en la semana de un docente, el docente en la de un salón.
     *
     * @return array{hours: string, place: string, kind: ?string}
     */
    abstract protected function detailOf(PublishedGroup $group, array $session): array;

    protected function document(PublishedSchedule $schedule, Term $term, Modality $modality): array
    {
        $groups = $this->groupsOf($schedule);

        return ['academic-load::pdf.week', [
            'title' => $this->title(),
            'subtitle' => $this->subtitle($schedule),
            'updated' => self::updated($schedule->updatedAt($groups)),
            ...self::week($groups, $schedule, fn (PublishedGroup $group, array $session): array => $this->detailOf($group, $session)),
        ], 'landscape'];
    }

    /**
     * La semana armada para la tabla: los días, los bloques y, por bloque y día, la celda que empieza ahí (con cuántos
     * bloques abarca) o `covered` si la tapa una de arriba.
     *
     * @param  list<PublishedGroup>  $groups
     * @param  callable(PublishedGroup, array{hours: string, place: string, kind: ?string}): array{hours: string, place: string, kind: ?string}  $detail
     * @return array{days: list<array{day: int, name: string}>, blocks: list<array{label: string}>, cells: array<int, array<int, array{span: int, items: list<array<string, mixed>>}|string>>, hours: int|float, usesTimeBlocks: bool}
     */
    public static function week(array $groups, PublishedSchedule $schedule, callable $detail): array
    {
        $colors = self::colors($groups);
        $ranges = [];
        $sessions = [];

        foreach ($groups as $group) {
            foreach ($group->sessions as $session) {
                $ranges[] = ['day' => $session->day, 'starts_at' => $session->startsAt, 'ends_at' => $session->endsAt];
                $sessions[] = [$group, $session];
            }
        }

        $grid = WeekGrid::of($schedule->profile())->covering($ranges);
        usort($sessions, fn (array $a, array $b): int => [$a[1]->day, WeekTime::minutes($a[1]->startsAt)] <=> [$b[1]->day, WeekTime::minutes($b[1]->startsAt)]);

        $cells = [];
        $open = [];

        foreach ($sessions as [$group, $session]) {
            $position = $grid->position($session->startsAt, $session->endsAt);
            $top = (int) floor($position['top']);
            $bottom = max($top + 1, (int) ceil($position['top'] + $position['span']));
            $item = [
                'label' => $group->label(),
                'subject' => $group->subject['name'],
                'color' => $colors[$group->subject['code']],
                ...$detail($group, self::session($session)),
            ];

            // Una sesión que empieza dentro de la celda abierta del día se lista en ella y la alarga: la tabla no
            // admite dos celdas en el mismo bloque.
            $current = $open[$session->day] ?? null;

            if ($current !== null && $top < $current['bottom']) {
                $cells[$session->day][$current['top']]['items'][] = $item;
                $open[$session->day]['bottom'] = max($current['bottom'], $bottom);
                $cells[$session->day][$current['top']]['span'] = $open[$session->day]['bottom'] - $current['top'];

                continue;
            }

            $cells[$session->day][$top] = ['span' => $bottom - $top, 'items' => [$item]];
            $open[$session->day] = ['top' => $top, 'bottom' => $bottom];
        }

        foreach ($cells as $day => $starts) {
            foreach ($starts as $top => $cell) {
                for ($row = $top + 1; $row < $top + $cell['span']; $row++) {
                    $cells[$day][$row] = 'covered';
                }
            }
        }

        return [
            'days' => array_map(fn (array $day): array => ['day' => $day['day'], 'name' => $day['name']], $grid->days),
            'blocks' => array_map(fn (array $block): array => ['label' => $block['label']], $grid->blocks),
            'cells' => $cells,
            'hours' => PublishedWeek::weeklyHours($groups),
            'usesTimeBlocks' => $schedule->profile()->usesTimeBlocks,
        ];
    }
}
