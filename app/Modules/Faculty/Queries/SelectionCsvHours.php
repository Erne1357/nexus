<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Models\Term;

/**
 * Las horas del renglón del CSV de criterios de la orden de selección del periodo (SEL-06), por docente: el respaldo de
 * PL-01 para quien no tiene horas frente a grupo por sus plazas en Nexus (TeachingHours::target), que HC-08 usa igual
 * que la selección (tarea 1.50). Faculty no conoce la orden de selección, que está a su derecha en el mapa de módulos:
 * liga NoSelectionCsvHours, y Staffing lo reemplaza en su register().
 */
interface SelectionCsvHours
{
    /**
     * @param  list<string>  $teacherIds
     * @return array<string, int> horas del CSV por id de docente; el que no viene en ningún CSV del periodo no aparece
     */
    public function forTerm(Term $term, array $teacherIds): array;
}
