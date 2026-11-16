<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Demand\Enums\ForecastMethod;

/**
 * Lo que da la fórmula para una materia (tarea 5.3): los repetidores esperados, los alumnos esperados (que ya los
 * incluyen, igual que el nuevo ingreso), los grupos sugeridos, con qué datos se calculó y la versión de la fórmula.
 */
final readonly class ForecastResult
{
    public function __construct(
        public int $expectedRepeaters,
        public int $expectedStudents,
        public int $suggestedGroups,
        public ForecastMethod $method,
        public string $formulaVersion,
    ) {}
}
