<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Events\Concerns\CanBeVetoed;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se va a borrar un departamento (DeleteDepartment). Evento con veto: se despacha dentro de la
 * transacción, antes de borrar, y un listener de un módulo de la derecha (Faculty y Facilities, que
 * todavía no lo escuchan) lo bloquea con block() si el departamento le sirve de algo, por ejemplo
 * porque tiene docentes o edificios. Si alguien bloquea, DeleteDepartment rechaza con sus motivos.
 */
final class DepartmentDeleting
{
    use CanBeVetoed, Dispatchable;

    public function __construct(public Department $department) {}
}
