<?php

namespace App\Modules\Imports\Sii;

/**
 * A qué materia de Nexus va una clave del SII en el kárdex: primero la de la retícula del plan del alumno
 * (`subject_for_plan`, porque una misma clave puede ser otra materia en otro plan, o la variante que se fundió en la
 * de la presencial) y, si su plan no tiene esa clave, la del alias general (`subject_for`).
 */
final readonly class KardexSubjects
{
    /**
     * @param  array<string|int, string>  $general  clave del SII → materia de Nexus
     * @param  array<string, array<string|int, string>>  $byPlan  plan de Nexus → clave del SII → materia de Nexus
     */
    public function __construct(private array $general, private array $byPlan) {}

    public function for(?string $plan, string $materia): ?string
    {
        return ($plan === null ? null : ($this->byPlan[$plan][$materia] ?? null)) ?? $this->general[$materia] ?? null;
    }
}
