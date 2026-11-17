<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\ForecastConfidence;
use App\Modules\Core\Models\Campus;
use Illuminate\Support\Collection;

/**
 * Lo que pinta «Precisión de la proyección» de una evaluación con el filtro de campus y el de departamento
 * (ForecastEvaluationsQuery::report).
 *
 * - `summary` y `counts`: el bloque de arriba (todo el Tec, el campus, el departamento o los dos); nulos hasta que la
 *   evaluación termina, porque `overall` se calcula al final.
 * - `careers`: una por carrera evaluada, la de menor error primero y las que no se pueden medir al final, cada una con
 *   el error de cada campus si no hay filtro de campus.
 * - `campuses` y `campusCounts`: las opciones del filtro de campus (los campus de la evaluación y cuántas carreras hay
 *   en cada uno); `departments`, las del filtro de departamento (el de la materia, como `totals.by_department`).
 *
 * @phpstan-type CareerAccuracy array{career: Career, metrics: ForecastMetrics, byCampus: list<array{campus: Campus, metrics: ForecastMetrics}>}
 */
final readonly class ForecastReport
{
    /**
     * @param  array{counted: int, withStudents: int, within: int, suggestedGroups: int, openedGroups: ?int}|null  $counts
     * @param  list<CareerAccuracy>  $careers
     * @param  Collection<int, Campus>  $campuses
     * @param  array<string, int>  $campusCounts  clave del campus → carreras
     * @param  Collection<int, Department>  $departments
     */
    public function __construct(
        public ?ForecastMetrics $summary,
        public ?array $counts,
        public array $careers,
        public Collection $campuses,
        public array $campusCounts,
        public Collection $departments,
        public ?Campus $campus,
        public ?Department $department,
    ) {}

    /**
     * Las carreras por su veredicto (DM-22), en el orden de la gráfica: sugerencia fuerte, referencia y sin medir. Solo
     * los grupos con alguna carrera.
     *
     * @return array<string, list<CareerAccuracy>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ([ForecastConfidence::Strong, ForecastConfidence::Reference, ForecastConfidence::Unmeasured] as $confidence) {
            $careers = array_values(array_filter($this->careers, fn (array $career): bool => $career['metrics']->confidence === $confidence));

            if ($careers !== []) {
                $groups[$confidence->value] = $careers;
            }
        }

        return $groups;
    }
}
