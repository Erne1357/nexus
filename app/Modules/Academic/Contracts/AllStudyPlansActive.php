<?php

namespace App\Modules\Academic\Contracts;

/**
 * Sin el módulo de alumnos nadie dice qué retícula está activa: no se restringe y el catálogo se ve como siempre (SEL-19).
 */
final class AllStudyPlansActive implements ActiveStudyPlans
{
    public function ids(): ?array
    {
        return null;
    }
}
