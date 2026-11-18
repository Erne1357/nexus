<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-08: un grupo con docente de honorarios se programa en los turnos donde declaró disponibilidad. La disponibilidad
 * declarada son sus bloques `preferred` (docs/arquitectura/solver.md, «Mapeo de reglas a cada motor»); lo que marcó
 * `unavailable` ya es HC-09.
 *
 * Unidad (supuesto por confirmar): una hora de sesión de un grupo de docente de honorarios fuera de sus bloques
 * preferidos; una fracción cuenta como hora. Un docente de honorarios que no declaró bloques no se penaliza. Se suma a
 * SC-04, que cuenta lo mismo para todos los docentes.
 */
final class HonorariosAvailability implements SoftRule
{
    public function rule(): string
    {
        return 'SC-08';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        return PreferredBlocks::outside($state, $scope, $context, fn (string $teacherId): bool => $state->teacher($teacherId)?->isHonorarios ?? false);
    }
}
