<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve y administra los buzones de puesto (CE-02; reglas-de-negocio.md, «Aprobaciones y permisos»): solo el admin,
 * con `core.mail.view_mailboxes` y `core.mail.manage_mailboxes`, candados de la familia `core.mail.*` (CF-03) que no se
 * dan a otro puesto. No se borran: se desactivan.
 */
final class PositionMailboxPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::MailViewMailboxes);
    }

    public function create(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::MailManageMailboxes);
    }

    public function update(User $user, PositionMailbox $mailbox): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::MailManageMailboxes);
    }

    public function deactivate(User $user, PositionMailbox $mailbox): bool
    {
        return $mailbox->is_active && $this->actor($user)->allowsAny(CorePermission::MailManageMailboxes);
    }
}
