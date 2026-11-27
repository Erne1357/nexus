<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Models\RoleAssignment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se nombró a una persona en un puesto (AppointRole). Sale después del commit, así que nadie se entera de un
 * nombramiento que se revirtió.
 */
final class RoleAppointed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public RoleAssignment $assignment,
    ) {}
}
