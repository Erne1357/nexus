<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\DepartmentUpdated;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Rules\DepartmentRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia la clave, el nombre o el estado (activar o desactivar) de un departamento: solo las claves
 * que recibe. Quién puede lo decide DepartmentPolicy antes de llamarla. Hace cumplir las mismas
 * reglas que CreateDepartment (DepartmentRules); conservar la clave propia vale.
 *
 * En una transacción vuelve a leer el departamento con bloqueo (`lockForUpdate`), así el valor
 * anterior de la bitácora es el confirmado aunque `$department` llegue viejo, y lo deja en
 * `$department`. Si nada cambió, no escribe, no registra ni avisa; si algo cambió, guarda, deja en la
 * bitácora el valor anterior y el nuevo de cada campo y, después del commit, avisa con
 * DepartmentUpdated.
 */
final class UpdateDepartment
{
    public function __construct(private readonly DepartmentRules $rules) {}

    /**
     * @param  array{code?: string, name?: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Department $department, array $data): Department
    {
        $data = Arr::only($data, ['code', 'name', 'is_active']);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        return DB::transaction(function () use ($department, $data): Department {
            $department->setRawAttributes($department->newQuery()->lockForUpdate()->findOrFail($department->getKey())->getAttributes(), sync: true);
            $old = $department->only(['code', 'name', 'is_active']);

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($data['code'], $department);
            }

            $department->fill($data);
            $changed = array_keys($department->getDirty());

            if ($changed === []) {
                return $department;
            }

            try {
                $department->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($department->code);
            }

            activity()
                ->performedOn($department)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $department->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó el departamento {$department->code}");

            DepartmentUpdated::dispatch($department, $changed);

            return $department;
        });
    }
}
