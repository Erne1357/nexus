<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\User;

/**
 * «Marcar todas como leídas» de la campana (tarea 2.9): solo las del usuario, y solo las que la campana le muestra: con
 * la carga académica oculta (MN-05), los avisos que llevan a sus pantallas se quedan sin leer, para que al mostrarla
 * vuelvan como nuevos. Da cuántas marcó.
 */
final class MarkAllNotificationsRead
{
    public function __construct(
        private readonly Actor $context,
        private readonly AcademicLoadVisibility $visibility,
    ) {}

    /**
     * @param  list<string>  $classes  Solo estos tipos de aviso; vacío, todos.
     */
    public function handle(User $user, array $classes = []): int
    {
        $query = $this->visibility->withoutHiddenNotifications($user->unreadNotifications()->getQuery(), $this->context);

        if ($classes !== []) {
            $query->whereIn('type', $classes);
        }

        return $query->update(['read_at' => now()]);
    }
}
