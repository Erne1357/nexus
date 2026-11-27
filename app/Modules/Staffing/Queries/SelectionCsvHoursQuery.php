<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Queries\SelectionCsvHours;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\SelectionEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Las horas del CSV de criterios (`staffing_selection_entries.base_hours`) de cada docente en las órdenes de selección no
 * canceladas del periodo, para el respaldo de PL-01 en HC-08 (SelectionCsvHours, tarea 1.50). Con renglones en varias
 * selecciones del periodo cuenta el menor que no es cero (supuesto por confirmar: el más restrictivo); un renglón con 0
 * horas es el de quien no venía en el CSV y no cuenta. Una consulta agregada.
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
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, min(base_hours) as hours')
            ->pluck('hours', 'teacher_id')
            ->map(fn ($hours): int => (int) $hours)
            ->all();
    }
}
