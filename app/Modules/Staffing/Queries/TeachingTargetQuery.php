<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Queries\TeacherTeachingHoursQuery;
use App\Modules\Faculty\Rules\TeachingHours;

/**
 * Las horas frente a grupo que se le piden a cada docente en la selección (SEL-05, PL-01): salen de sus plazas
 * vigentes en Nexus al inicio del periodo (TeacherTeachingHoursQuery) y, para quien no tiene plazas en Nexus, de las
 * horas de su renglón del CSV (TeachingHours::fromCsvHours, supuesto por confirmar), también si sus plazas dan 0 h frente a grupo (solo apoyo u honorarios; supuesto por confirmar). Una consulta de plazas, sin N+1.
 */
final class TeachingTargetQuery
{
    public function __construct(private readonly TeacherTeachingHoursQuery $teaching) {}

    /**
     * @param  array<string, int>  $csvHours  horas del CSV por id de docente (0 si no vienen en él)
     * @return array<string, int> horas frente a grupo por id de docente
     */
    public function forTerm(Term $term, array $csvHours): array
    {
        // La pantalla «Oferta» carga el periodo sin sus fechas (solo id, clave y nombre): sin `starts_on` se busca completo.
        $term = $term->starts_on === null ? Term::query()->whereKey($term->getKey())->firstOrFail() : $term;
        $fromPositions = $csvHours === [] ? [] : $this->teaching->forTerm($term, array_map('strval', array_keys($csvHours)));
        $targets = [];

        foreach ($csvHours as $teacherId => $csv) {
            // Plazas que dan 0 h frente a grupo (solo apoyo u honorarios) cuentan como sin plaza: se usa el CSV (supuesto por confirmar).
            $targets[$teacherId] = TeachingHours::target($fromPositions[$teacherId] ?? 0, $csv);
        }

        return $targets;
    }
}
