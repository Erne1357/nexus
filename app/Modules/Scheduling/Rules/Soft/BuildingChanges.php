<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Rules\Timeline;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-06: un docente no cambia de edificio entre horas consecutivas.
 *
 * Unidad (supuesto por confirmar): un cambio de edificio entre dos sesiones seguidas del docente, sin hueco entre ellas
 * (una termina cuando empieza la otra), las dos con salón de edificio conocido (SoftContext); al menos una toca el
 * alcance. Un cambio de campus también es de edificio; el traslado justo además cuenta en SC-09.
 */
final class BuildingChanges implements SoftRule
{
    public function rule(): string
    {
        return 'SC-06';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $changes = 0;

        foreach ($scope->teacherIds($state) as $teacherId) {
            foreach (Timeline::byDay($state->teacherSessions($teacherId)) as $day) {
                for ($i = 0, $last = count($day) - 1; $i < $last; $i++) {
                    [$before, $after] = [$day[$i], $day[$i + 1]];
                    $from = $before->roomId === null ? null : $context->buildings[$before->roomId] ?? null;
                    $to = $after->roomId === null ? null : $context->buildings[$after->roomId] ?? null;

                    if ($after->start === $before->end && $from !== null && $to !== null && $from !== $to
                        && $scope->touches($before->groupId, $after->groupId, $teacherId)) {
                        $changes++;
                    }
                }
            }
        }

        return $changes;
    }
}
