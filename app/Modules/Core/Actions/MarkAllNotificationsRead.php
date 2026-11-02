<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;

/**
 * «Marcar todas como leídas» de la campana (tarea 2.9): solo las del usuario. Da cuántas marcó.
 */
final class MarkAllNotificationsRead
{
    public function handle(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
