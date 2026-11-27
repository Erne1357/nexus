<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Models\RoleAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se terminó o se quitó un nombramiento (EndRoleAssignment); solo sale si algo cambió, después del commit. Con
 * `Removed` la fila ya no existe.
 */
final class RoleAssignmentEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public RoleAssignment $assignment,
        public AssignmentEnding $ending,
    ) {}
}
