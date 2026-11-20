<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use Spatie\Activitylog\Models\Activity;

/**
 * Quién ve la bitácora (CF-S5, CF-03): el admin y, de fábrica, el directivo (AZ-03, confirmado el 20 de noviembre de
 * 2026), con `core.activity.view`, un candado que el panel no da a otro puesto. No hay otra ability: la bitácora no se
 * edita ni se borra (HC-18).
 */
final class ActivityPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::ActivityView);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $this->actor($user)->allows(CorePermission::ActivityView);
    }
}
