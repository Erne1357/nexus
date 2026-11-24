<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Faculty\Models\Teacher;

/**
 * Si el docente está en la cola de una orden de selección no cancelada: entonces su invitación de acceso se puede
 * reenviar a su correo personal cuando no tiene institucional (SEL-13). Faculty no conoce la orden de selección, que
 * está a su derecha en el mapa de módulos: liga NoSelectionQueueMembership, y Staffing lo reemplaza en su register().
 */
interface SelectionQueueMembership
{
    public function includes(Teacher $teacher): bool;
}
