<?php

namespace App\Modules\Core\Enums;

/**
 * Qué hizo EndRoleAssignment: terminó el nombramiento (`Ended`), lo quitó porque todavía no empezaba (`Removed`) o no
 * cambió nada porque ya terminaba antes (`Unchanged`).
 */
enum AssignmentEnding: string
{
    case Ended = 'ended';
    case Removed = 'removed';
    case Unchanged = 'unchanged';
}
