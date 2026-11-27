<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Queries\TeacherTeachingHoursQuery;
use App\Modules\Faculty\Rules\TeachingHours;

/**
 * Las horas frente a grupo que se le piden a cada docente en la selección (SEL-05, PL-01): salen de sus plazas
 * vigentes en Nexus al inicio del periodo (TeacherTeachingHoursQuery) y, para quien no tiene plazas en Nexus, de las
 * horas de su renglón del CSV (TeachingHours::fromCsvHours, supuesto por confirmar), también si sus plazas dan 0 h frente a grupo (solo honorarios; supuesto por confirmar). Con plaza administrativa (PL-03) no hay banda: su objetivo es 0 y administrativeForTerm lo identifica. Una consulta de plazas, sin N+1.
 */
final class TeachingTargetQuery
{
    public function __construct(private readonly TeacherTeachingHoursQuery $teaching) {}

    /**
     * PL-03: las horas administrativas de los docentes de plaza administrativa (apoyo y asistencia); quien no aparece no
     * lo es y se mide con su banda (forTerm).
     *
     * @param  list<string>  $teacherIds
     * @return array<string, int>
     */
    public function administrativeForTerm(Term $term, array $teacherIds): array
    {
        $term = $term->starts_on === null ? Term::query()->whereKey($term->getKey())->firstOrFail() : $term;

        return $teacherIds === [] ? [] : $this->teaching->administrativeForTerm($term, $teacherIds);
    }

    /**
     * @param  array<string, int>  $csvHours  horas del CSV por id de docente (0 si no vienen en él)
     * @return array<string, int> horas frente a grupo por id de docente
     */
    public function forTerm(Term $term, array $csvHours): array
    {
        // La pantalla «Oferta» carga el periodo sin sus fechas (solo id, clave y nombre): sin `starts_on` se busca completo.
        $term = $term->starts_on === null ? Term::query()->whereKey($term->getKey())->firstOrFail() : $term;
        $ids = array_map('strval', array_keys($csvHours));
        $fromPositions = $csvHours === [] ? [] : $this->teaching->forTerm($term, $ids);
        $administrative = $csvHours === [] ? [] : $this->teaching->administrativeForTerm($term, $ids);
        $targets = [];

        foreach ($csvHours as $teacherId => $csv) {
            // PL-03: con plaza administrativa no hay banda; no se le inventan horas frente a grupo con el CSV.
            if (isset($administrative[$teacherId])) {
                $targets[$teacherId] = 0;

                continue;
            }

            // Plazas que dan 0 h frente a grupo (solo honorarios) cuentan como sin plaza: se usa el CSV (supuesto por confirmar).
            $targets[$teacherId] = TeachingHours::target($fromPositions[$teacherId] ?? 0, $csv);
        }

        return $targets;
    }
}
