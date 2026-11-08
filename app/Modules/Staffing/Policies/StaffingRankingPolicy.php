<?php

namespace App\Modules\Staffing\Policies;

use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\StaffingPermission;

/**
 * Quién ve el ranking de docentes de un grupo: quienes asignan y la Subdirección.
 */
final class StaffingRankingPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user, Group $group): bool
    {
        return $this->actor($user)->allows(StaffingPermission::RankingView, Target::of(
            campus: $group->campus_id,
            unit: $group->department_id,
        ));
    }
}
