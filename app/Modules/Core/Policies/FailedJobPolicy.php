<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve las colas y sus trabajos fallidos, y quién los reintenta o descarta (CF-S8, CF-03): solo el admin, con
 * `core.queue.view` y `core.queue.manage`, candados que no se dan a otro puesto.
 */
final class FailedJobPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::QueueView);
    }

    public function retry(User $user, FailedJob $job): bool
    {
        return $this->actor($user)->allows(CorePermission::QueueManage);
    }

    public function forget(User $user, FailedJob $job): bool
    {
        return $this->actor($user)->allows(CorePermission::QueueManage);
    }
}
