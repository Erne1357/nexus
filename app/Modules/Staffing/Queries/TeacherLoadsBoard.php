<?php

namespace App\Modules\Staffing\Queries;

/**
 * El tablero de carga por docente de un departamento y un periodo: sus docentes con carga calculada y los totales.
 * `pending` cuenta a los docentes del departamento que todavía no tienen fila en `teacher_term_loads`.
 */
final readonly class TeacherLoadsBoard
{
    /**
     * @param  list<TeacherLoadRow>  $rows  por nombre
     */
    public function __construct(
        public array $rows,
        public int $pending,
    ) {}

    public function isComputed(): bool
    {
        return $this->rows !== [];
    }

    /**
     * @param  list<TeacherLoadRow>|null  $rows  un subconjunto; null, todos
     * @return array{teachers: int, base: int, external: int, assigned: int, remaining: int, groups: int, none: int, over: int, honorarios: int, byCampus: array<string, int>}
     */
    public function totals(?array $rows = null): array
    {
        $rows ??= $this->rows;
        $byCampus = [];

        foreach ($rows as $row) {
            foreach ($row->hoursByCampus as $campus => $hours) {
                $byCampus[$campus] = ($byCampus[$campus] ?? 0) + $hours;
            }
        }

        ksort($byCampus);

        return [
            'teachers' => count($rows),
            'base' => array_sum(array_map(fn (TeacherLoadRow $row): int => $row->baseHours, $rows)),
            'external' => array_sum(array_map(fn (TeacherLoadRow $row): int => $row->externalHours, $rows)),
            'assigned' => array_sum(array_map(fn (TeacherLoadRow $row): int => $row->assignedHours, $rows)),
            'remaining' => array_sum(array_map(fn (TeacherLoadRow $row): int => $row->isHonorarios ? 0 : max(0, $row->remainingHours), $rows)),
            'groups' => array_sum(array_map(fn (TeacherLoadRow $row): int => $row->groupsCount, $rows)),
            'none' => count(array_filter($rows, fn (TeacherLoadRow $row): bool => $row->status() === TeacherLoadRow::NONE)),
            'over' => count(array_filter($rows, fn (TeacherLoadRow $row): bool => $row->status() === TeacherLoadRow::OVER)),
            'honorarios' => count(array_filter($rows, fn (TeacherLoadRow $row): bool => $row->isHonorarios)),
            'byCampus' => $byCampus,
        ];
    }
}
