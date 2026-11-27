<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;

/**
 * Sin la orden de selección no hay CSV de criterios: las horas frente a grupo salen solo de las plazas (PL-01).
 */
final class NoSelectionCsvHours implements SelectionCsvHours
{
    public function forTerm(Term $term, array $teacherIds): array
    {
        return [];
    }
}
