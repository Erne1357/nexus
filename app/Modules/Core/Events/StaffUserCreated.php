<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un usuario de personal, siempre sin acceso, hasta su invitación (CreateStaffUser, CF-07). Sale
 * después del commit.
 */
final class StaffUserCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}
}
