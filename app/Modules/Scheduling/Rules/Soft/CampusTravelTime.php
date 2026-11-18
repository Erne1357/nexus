<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Rules\Constraints\CampusTravel;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-09: entre dos sesiones seguidas del docente en campus distintos quedan al menos los minutos de traslado
 * (`staffing.min_campus_travel_minutes` de la foto, 60 por omisión). Cuenta con la misma regla que avisa el validador,
 * CampusTravel, que reporta cada par en los dos grupos.
 *
 * Unidad (supuesto por confirmar): un par de sesiones seguidas del docente sin el tiempo de traslado, que toca el
 * alcance.
 */
final class CampusTravelTime implements SoftRule
{
    private readonly CampusTravel $travel;

    public function __construct()
    {
        $this->travel = new CampusTravel;
    }

    public function rule(): string
    {
        return 'SC-09';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $warnings = 0;

        foreach ($this->travel->check($state, $scope) as $warning) {
            $warnings++;
        }

        return intdiv($warnings, 2);
    }
}
