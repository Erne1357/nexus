<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Faculty\Models\Teacher;

/**
 * Sin la orden de selección ningún docente está en una cola: la invitación va solo al correo institucional (AC-07).
 */
final class NoSelectionQueueMembership implements SelectionQueueMembership
{
    public function includes(Teacher $teacher): bool
    {
        return false;
    }
}
