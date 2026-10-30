<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un usuario de personal con la contraseña por defecto (CreateStaffUser). Sale
 * después del commit.
 */
final class StaffUserCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}
}
