<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\DepartmentHead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se nombró a un jefe o a un jefe de proyecto docente, con su rol y su alcance (AppointDepartmentHead). Sale
 * después del commit.
 */
final class DepartmentHeadAppointed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public DepartmentHead $head,
    ) {}
}
