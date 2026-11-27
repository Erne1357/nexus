<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Queries\SelectionCsvHours;
use App\Modules\Faculty\Rules\TeachingHours;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\SelectionEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las horas frente a grupo que da el CSV de criterios (`staffing_selection_entries.base_hours`) de cada docente en las
 * órdenes de selección no canceladas del periodo, para el respaldo de PL-01 en HC-08 (SelectionCsvHours, tarea 1.50):
 * cada renglón pasa por TeachingHours::fromCsvHours (40 es 22, 30 es 16, 20 es 12) y, con renglones en varias
 * selecciones, cuenta el menor que no es cero (supuesto por confirmar: el más restrictivo); un renglón con 0 horas es el
 * de quien no venía en el CSV y no cuenta. Una consulta.
 */
final class SelectionCsvHoursQuery implements SelectionCsvHours
{
    public function forTerm(Term $term, array $teacherIds): array
    {
        if ($teacherIds === []) {
            return [];
        }

        return SelectionEntry::query()
            ->whereIn('teacher_id', $teacherIds)
            ->where('base_hours', '>', 0)
            ->whereHas('selection', fn (Builder $selection) => $selection->where('term_id', $term->getKey())->where('status', '!=', SelectionStatus::Cancelled->value))
            ->get(['teacher_id', 'base_hours'])
            ->groupBy('teacher_id')
            ->map(fn ($rows): int => (int) $rows->map(fn (SelectionEntry $entry): int => TeachingHours::fromCsvHours((int) $entry->base_hours))
                ->filter(fn (int $hours): bool => $hours > 0)->min())
            ->filter(fn (int $hours): bool => $hours > 0)
            ->all();
    }
}
