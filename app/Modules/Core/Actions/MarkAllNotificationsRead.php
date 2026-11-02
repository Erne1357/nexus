<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\User;

/**
 * «Marcar todas como leídas» de la campana (tarea 2.9): solo las del usuario. Da cuántas marcó.
 */
final class MarkAllNotificationsRead
{
    /**
     * @param  list<string>  $classes  Solo estos tipos de aviso; vacío, todos.
     */
    public function handle(User $user, array $classes = []): int
    {
        $query = $user->unreadNotifications();

        if ($classes !== []) {
            $query->whereIn('type', $classes);
        }

        return $query->update(['read_at' => now()]);
    }
}
