<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Department;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron la clave, el nombre o el estado de un departamento (UpdateDepartment). Sale después del
 * commit, así que nadie se entera de un cambio que se revirtió.
 */
final class DepartmentUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $changed  columnas que cambiaron (p. ej. `['name', 'is_active']`)
     */
    public function __construct(
        public Department $department,
        public array $changed,
    ) {}
}
