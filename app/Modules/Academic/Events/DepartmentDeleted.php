<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Department;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se borró un departamento (DeleteDepartment), con sus alcances ya cerrados. Sale después del commit.
 */
final class DepartmentDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Department $department) {}
}
