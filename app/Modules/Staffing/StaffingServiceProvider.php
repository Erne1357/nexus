<?php

namespace App\Modules\Staffing;

use App\Modules\Core\ModuleServiceProvider;

/**
 * Módulo Staffing: ranking docente y asignación de docentes a los grupos (docs/dominio/modelo-de-datos.md,
 * "Staffing"). Está a la derecha de Scheduling (ADR-0020); por ahora solo usa Core, Academic y Faculty.
 */
final class StaffingServiceProvider extends ModuleServiceProvider
{
    public function navigation(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];
    }

    public function grants(): array
    {
        return [];
    }
}
