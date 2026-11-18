<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-04: el docente imparte en sus bloques `preferred` (disponibilidad del periodo, Faculty).
 *
 * Unidad (supuesto por confirmar): una hora de sesión fuera de los bloques preferidos del docente del grupo; una
 * fracción cuenta como hora. Un docente que no declaró bloques preferidos no se penaliza. Cuenta los grupos activos del
 * alcance con docente.
 */
final class PreferredBlocks implements SoftRule
{
    public function rule(): string
    {
        return 'SC-04';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        return self::outside($state, $scope, $context, fn (string $teacherId): bool => true);
    }

    /**
     * Las horas fuera de los bloques preferidos de los grupos del alcance cuyo docente cumple la condición.
     *
     * @param  callable(string): bool  $counts
     */
    public static function outside(ScheduleState $state, ValidationScope $scope, SoftContext $context, callable $counts): int
    {
        $hours = 0;

        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);
            $teacherId = $group === null || ! $group->isActive ? null : $group->teacherId;
            $blocks = $teacherId === null ? [] : $context->preferred[$teacherId] ?? [];

            if ($blocks === [] || ! $counts($teacherId)) {
                continue;
            }

            foreach ($state->sessionsOf($groupId) as $session) {
                $hours += Timeline::hours(Timeline::uncovered($session, $blocks));
            }
        }

        return $hours;
    }
}
