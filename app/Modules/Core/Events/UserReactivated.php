<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se reactivó una cuenta dada de baja, sin acceso (ReactivateUser, CF-06). Sale después del commit.
 */
final class UserReactivated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}
}
