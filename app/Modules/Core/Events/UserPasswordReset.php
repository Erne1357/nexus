<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se reinició la contraseña de un usuario a la de defecto (ResetUserPassword). Sale después del
 * commit.
 */
final class UserPasswordReset implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public ?User $actor = null,
    ) {}
}
