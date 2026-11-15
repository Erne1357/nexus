<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de baja la cuenta de un usuario del personal (DeactivateUser, CF-05), con su motivo. Sale después del commit.
 */
final class UserDeactivated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public DeactivationReason $reason,
    ) {}
}
