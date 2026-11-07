<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * HC-13: solo se pueden crear grupos de materias activas de planes activos, en carreras ofrecidas en el campus.
 * Cada razón que no se cumple da su propio choque.
 */
final class OfferableGroup implements Constraint
{
    public function rule(): string
    {
        return 'HC-13';
    }

    public function check(ScheduleState $state, ValidationScope $scope): iterable
    {
        foreach ($scope->groupIds() as $groupId) {
            $group = $state->group($groupId);

            if ($group === null || ! $group->isActive) {
                continue;
            }

            $label = $state->labelOf($groupId);
            $reasons = [];

            if ($state->subject($group->subjectId)?->isActive === false) {
                $reasons['subject_inactive'] = [
                    "{$label} es de una materia inactiva.",
                    ['subject_id' => $group->subjectId],
                ];
            }

            if (! $state->isPlanActive($group->studyPlanId)) {
                $reasons['plan_inactive'] = [
                    "{$label} es de un plan de estudios que no está activo.",
                    ['study_plan_id' => $group->studyPlanId],
                ];
            }

            if (! $state->isOffered($group->ownerCareerId, $group->campusId)) {
                $reasons['career_not_offered'] = [
                    "{$label} es de la carrera {$group->ownerCareerId}, que no se ofrece en el campus del grupo.",
                    ['career_id' => $group->ownerCareerId, 'campus_id' => $group->campusId],
                ];
            }

            foreach ($reasons as $reason => [$message, $related]) {
                yield Violation::hard($this->rule(), $message, 'group', $groupId, $groupId, ['reason' => $reason, ...$related]);
            }
        }
    }
}
