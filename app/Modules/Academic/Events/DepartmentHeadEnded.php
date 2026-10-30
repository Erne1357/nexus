<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\DepartmentHead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Terminó una jefatura y su alcance (EndDepartmentHead): la que ya había empezado tiene fecha de fin; la que no,
 * quedó borrada (`$head->trashed()`). Sale después del commit.
 */
final class DepartmentHeadEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public DepartmentHead $head,
    ) {}
}
