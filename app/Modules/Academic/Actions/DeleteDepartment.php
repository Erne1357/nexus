<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\DepartmentDeleted;
use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Academic\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Borra (borrado lógico) un departamento. Solo si no tiene carreras ni materias sin borrar; si no,
 * una ValidationException sobre `department` que dice cuántas. Antes de borrar despacha
 * DepartmentDeleting, dentro de la transacción, para que un módulo de la derecha lo vete: la
 * excepción lleva sus motivos. Si pasa, cierra los alcances de sus jefes con CloseRoleScopes (S2),
 * borra y deja bitácora con los datos que tenía. Después del commit avisa con DepartmentDeleted.
 *
 * Vuelve a leer el departamento con bloqueo, así que las carreras y materias que cuenta son las de
 * ese momento. Quién puede borrar lo decide DepartmentPolicy antes de llamarla.
 */
final class DeleteDepartment
{
    public function __construct(private readonly CloseRoleScopes $closeRoleScopes) {}

    /**
     * @throws ValidationException
     */
    public function handle(Department $department): Department
    {
        return DB::transaction(function () use ($department): Department {
            $department->setRawAttributes($department->newQuery()->lockForUpdate()->findOrFail($department->getKey())->getAttributes(), sync: true);

            $careers = $department->careers()->count();
            $subjects = $department->subjects()->count();

            if ($careers > 0 || $subjects > 0) {
                $parts = array_filter([
                    $careers > 0 ? $careers.($careers === 1 ? ' carrera' : ' carreras') : null,
                    $subjects > 0 ? $subjects.($subjects === 1 ? ' materia' : ' materias') : null,
                ]);

                throw ValidationException::withMessages(['department' => 'No se puede borrar: tiene '.implode(' y ', $parts).'.']);
            }

            $event = new DepartmentDeleting($department);
            event($event);

            if ($event->blocked()) {
                throw ValidationException::withMessages(['department' => $event->reasons()]);
            }

            $this->closeRoleScopes->handle('department_id', $department->id);

            $department->delete();

            activity()
                ->performedOn($department)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $department->only(['code', 'name', 'is_active'])])
                ->log("Borró el departamento {$department->code}");

            DepartmentDeleted::dispatch($department);

            return $department;
        });
    }
}
