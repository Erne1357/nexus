<?php

namespace App\Modules\Faculty\Enums;

/**
 * Qué hizo SyncTeacherAccess con el acceso de un docente: le creó usuario, le ligó uno existente con su número
 * (S15), cambió algo más (activó o desactivó el usuario, el rol `docente`, sus alcances o sus jefaturas), nada, o
 * no lo tocó porque el usuario con su número ya es de otro docente (S15).
 */
enum AccessSync: string
{
    case Created = 'created';
    case Linked = 'linked';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
}
