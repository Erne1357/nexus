<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Models\User;

/**
 * Una notificación que elige a qué correo le llega a cada usuario, en lugar de `users.email` (CV11, tarea 1.21):
 * User::routeNotificationForMail() se lo pregunta cuando la notificación lo implementa, también al salir de la cola.
 * Core no conoce las fichas de los módulos de la derecha; el módulo que manda el aviso decide la dirección. Las
 * notificaciones que no lo implementan siguen yendo a `users.email`.
 */
interface RoutesMailAddress
{
    /**
     * El correo al que va el aviso para `$user`, o null si a ese usuario no le llega por correo.
     */
    public function mailAddressFor(User $user): ?string;
}
