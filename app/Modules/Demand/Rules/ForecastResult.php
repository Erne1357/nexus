<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Demand\Enums\ForecastMethod;

/**
 * Lo que da la fórmula para una materia (tarea 5.3): los repetidores esperados, los alumnos esperados (que ya los
 * incluyen, igual que el nuevo ingreso), los grupos sugeridos, con qué datos se calculó y la versión de la fórmula.
 * `nonEnrollmentRate` es la no inscripción efectiva de sus elegibles con los tramos de distancia (1 − inscritos sin
 * redondear / elegibles; sin elegibles, la de la materia en todos los tramos) o null si no hubo tasas (DM-03).
 */
final readonly class ForecastResult
{
    public function __construct(
        public int $expectedRepeaters,
        public int $expectedStudents,
        public int $suggestedGroups,
        public ForecastMethod $method,
        public string $formulaVersion,
        public ?float $nonEnrollmentRate = null,
    ) {}
}
