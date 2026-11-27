<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién reintenta o descarta un correo de puesto en espera (CE-02): solo el admin, con `core.mail.manage_mailboxes`
 * (candado de `core.mail.*`, CF-03). Se reintenta uno que falló; se descarta uno que espera o que falló, nunca uno que
 * ya se tomó para mandarse.
 */
final class HeldPositionMailPolicy
{
    use AuthorizesActor;

    public function retry(User $user, HeldPositionMail $held): bool
    {
        return $held->failed_at !== null && $held->discarded_at === null && $this->actor($user)->allowsAny(CorePermission::MailManageMailboxes);
    }

    public function discard(User $user, HeldPositionMail $held): bool
    {
        return $held->released_at === null && $held->discarded_at === null && $this->actor($user)->allowsAny(CorePermission::MailManageMailboxes);
    }
}
