<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Enums\Role;

/**
 * Un aviso dirigido a un puesto (spec 8.4): al abrirlo desde la campana, la sesión actúa con ese puesto, para que la
 * pantalla del destino se vea con el alcance con que se avisó (OpenNotification). Si la persona ya no lo tiene, el
 * aviso se abre como cualquiera.
 */
interface AddressedToRole
{
    public static function addressedRole(): Role;
}
