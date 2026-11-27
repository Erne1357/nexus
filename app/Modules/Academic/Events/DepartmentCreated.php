<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Department;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se dio de alta un departamento (CreateDepartment). Sale después del commit, así que nadie se entera de un alta que se revirtió.
 */
final class DepartmentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Department $department) {}
}
