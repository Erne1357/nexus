<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Rules\Timeline;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * SC-03: la materia se imparte en su salón por defecto o sugerido (`subject_room_defaults` de su campus, con prioridad).
 *
 * Unidad (supuesto por confirmar): una hora de sesión en un salón que no está entre los por defecto o sugeridos de la
 * materia en el campus del grupo; una fracción cuenta como hora. Cualquier salón de la lista cuenta igual, sea cual sea
 * su prioridad. Una materia sin salón por defecto en ese campus, o una sesión sin salón, no se penaliza. Cuenta los
 * grupos activos del alcance.
 */
final class DefaultRoom implements SoftRule
{
    public function rule(): string
    {
        return 'SC-03';
    }

    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int
    {
        $hours = 0;

        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);
            $defaults = $group === null || ! $group->isActive ? [] : $context->roomDefaultsOf($group->subjectId, $group->campusId);

            if ($defaults === []) {
                continue;
            }

            foreach ($state->sessionsOf($groupId) as $session) {
                if ($session->roomId !== null && ! isset($defaults[$session->roomId])) {
                    $hours += Timeline::hours($session->minutes());
                }
            }
        }

        return $hours;
    }
}
