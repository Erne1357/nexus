<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron el nombre o el correo de una cuenta (UpdateUserProfile, CF-S3); `$changed` son los campos que cambiaron.
 * Sale después del commit.
 */
final class UserProfileUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public User $user,
        public array $changed,
    ) {}
}
