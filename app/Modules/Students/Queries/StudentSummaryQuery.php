<?php

namespace App\Modules\Students\Queries;

use App\Modules\Students\Enums\SubjectStanding;

/**
 * El tablero «Resumen» de un alumno (AC-15, tarea 1.67; DESIGN.md, «Resumen del alumno»), armado desde su StudentRecord y sus
 * promedios (StudentAveragesQuery): el avance en créditos, el mini avance por semestre, lo que cursa, lo que necesita atención y
 * lo que puede seleccionar. Es el mismo para el alumno (en segunda persona) y para el personal (en tercera, `$own` en false).
 *
 * @phpstan-type Column array{key: string, label: string, short: string, current: bool, specialty: bool, done: int, total: int, cells: list<GridCell>}
 */
final class StudentSummaryQuery
{
    /** Lo más que lista «Puede seleccionarla»: sin desplazamiento infinito, el resto va a la retícula (AC-15). */
    public const int SELECTABLE_LIMIT = 6;

    public function __construct(private readonly StudentAveragesQuery $averages) {}

    /**
     * @return array{
     *     averages: StudentAverages,
     *     lastTermLabel: ?string,
     *     percent: int,
     *     percentWithTaking: int,
     *     taking: list<GridCell>,
     *     takingCredits: int,
     *     termLabel: ?string,
     *     attention: list<array{cell: GridCell, why: string}>,
     *     selectable: list<GridCell>,
     *     selectableTotal: int,
     *     columns: list<Column>,
     *     legend: list<SubjectStanding>,
     *     specialty: ?string,
     * }
     */
    public function forRecord(StudentRecord $record, bool $own): array
    {
        $cells = array_merge(...array_values($record->semesters ?: [[]]));
        $taking = $this->sorted(array_filter($cells, fn (GridCell $cell): bool => $cell->standing === SubjectStanding::Cursando));
        $takingCredits = array_sum(array_map(fn (GridCell $cell): int => $cell->credits, $taking));
        $available = array_flip($record->availableIds);
        $averages = $this->averages->forStudent($record->student);

        $selectable = array_filter($cells, fn (GridCell $cell): bool => $cell->standing->countsAsSelectable() && isset($available[$cell->subjectId]));
        usort($selectable, fn (GridCell $a, GridCell $b): int => [$a->standing === SubjectStanding::ReprobadaPrioridad ? 0 : 1, $a->semester, $a->code] <=> [$b->standing === SubjectStanding::ReprobadaPrioridad ? 0 : 1, $b->semester, $b->code]);

        $attention = array_map(fn (GridCell $cell): array => ['cell' => $cell, 'why' => self::why($cell->standing, $own)], $this->sorted(array_filter($cells, fn (GridCell $cell): bool => in_array($cell->standing, [SubjectStanding::ReprobadaPrioridad, SubjectStanding::ACursoEspecial, SubjectStanding::CursoEspecialReprobado], true))));

        $termCode = collect($taking)->pluck('termCode')->filter()->max();

        return [
            'averages' => $averages,
            'lastTermLabel' => $averages->lastTermCode === null ? null : self::termLabel($averages->lastTermCode),
            'percent' => self::percent($record->creditsApproved, $record->totalCredits),
            'percentWithTaking' => self::percent($record->creditsApproved + $takingCredits, $record->totalCredits),
            'taking' => $taking,
            'takingCredits' => $takingCredits,
            'termLabel' => $termCode === null ? null : self::termLabel($termCode),
            'attention' => $attention,
            'selectable' => array_slice($selectable, 0, self::SELECTABLE_LIMIT),
            'selectableTotal' => $record->selectable,
            'columns' => $this->columns($record),
            'legend' => array_values(array_filter(SubjectStanding::cases(), fn (SubjectStanding $standing): bool => array_filter($cells, fn (GridCell $cell): bool => $cell->standing === $standing) !== [])),
            'specialty' => count($record->specialties) === 1 ? $record->specialties[0]['name'] : null,
        ];
    }

    /**
     * Una columna por semestre con las materias del tronco común y, al final, una sola «Esp.» con las de especialidad.
     *
     * @return list<Column>
     */
    private function columns(StudentRecord $record): array
    {
        $columns = [];
        $special = [];

        foreach ($record->semesters as $semester => $semesterCells) {
            $common = array_values(array_filter($semesterCells, fn (GridCell $cell): bool => $cell->specialtyId === null));
            $special = [...$special, ...array_filter($semesterCells, fn (GridCell $cell): bool => $cell->specialtyId !== null)];

            if ($common !== []) {
                $columns[] = self::column((string) $semester, $semester.'.º', (string) $semester, $common, $record->student->current_semester === $semester, false);
            }
        }

        if ($special !== []) {
            $columns[] = self::column('esp', 'Especialidad', 'Esp.', $this->sorted($special), false, true);
        }

        return $columns;
    }

    /**
     * @param  list<GridCell>  $cells
     * @return Column
     */
    private static function column(string $key, string $label, string $short, array $cells, bool $current, bool $specialty): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'short' => $short,
            'current' => $current,
            'specialty' => $specialty,
            'done' => count(array_filter($cells, fn (GridCell $cell): bool => $cell->standing === SubjectStanding::Acreditada)),
            'total' => count($cells),
            'cells' => $cells,
        ];
    }

    /**
     * @param  array<int, GridCell>  $cells
     * @return list<GridCell>
     */
    private function sorted(array $cells): array
    {
        $cells = array_values($cells);
        usort($cells, fn (GridCell $a, GridCell $b): int => [$a->semester, $a->code] <=> [$b->semester, $b->code]);

        return $cells;
    }

    private static function percent(int $credits, int $total): int
    {
        return $total > 0 ? (int) min(100, round($credits / $total * 100)) : 0;
    }

    /**
     * Lo que significa el estado de atención, con las reglas de «Retícula del alumno» (confirmado el 29 de octubre de 2026).
     */
    private static function why(SubjectStanding $standing, bool $own): string
    {
        return match ($standing) {
            SubjectStanding::ReprobadaPrioridad => $own ? 'La reprobaste en ordinario: tienes prioridad para seleccionarla.' : 'La reprobó en ordinario: tiene prioridad para seleccionarla.',
            SubjectStanding::ACursoEspecial => $own ? 'La reprobaste en repetición: solo puedes cursarla en curso especial.' : 'La reprobó en repetición: solo puede cursarla en curso especial.',
            default => $own ? 'La reprobaste en curso especial: te das de baja en automático.' : 'La reprobó en curso especial: se da de baja en automático.',
        };
    }

    /**
     * El nombre de un periodo del SII (`20253` es «Ago-Dic 2025»), como en StudentRecordQuery.
     */
    private static function termLabel(string $code): string
    {
        return match (substr($code, -1)) {
            '1' => 'Ene-Jun',
            '2' => 'Verano',
            default => 'Ago-Dic',
        }.' '.substr($code, 0, 4);
    }
}
