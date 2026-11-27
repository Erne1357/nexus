<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\DepartmentCreated;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Rules\DepartmentRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un departamento. Recibe datos ya validados en forma (el componente) y hace cumplir la
 * regla de DepartmentRules: clave en mayúsculas, sin espacios y única también entre los borrados.
 * Una regla rota es una ValidationException sobre `code`.
 *
 * En una transacción revisa la clave, escribe y deja bitácora; si otra sesión guarda la misma clave
 * entre la revisión y el insert, la restricción única de la base responde con el mismo error.
 * Después del commit avisa con DepartmentCreated.
 */
final class CreateDepartment
{
    public function __construct(private readonly DepartmentRules $rules) {}

    /**
     * @param  array{code: string, name: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Department
    {
        $code = $this->rules->normalizeCode($data['code']);

        return DB::transaction(function () use ($code, $data): Department {
            $this->rules->ensureCodeIsFree($code);

            try {
                $department = Department::create(['code' => $code, 'name' => $data['name'], 'is_active' => true]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($department)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $department->only(['code', 'name', 'is_active'])])
                ->log("Dio de alta el departamento {$department->code}");

            DepartmentCreated::dispatch($department);

            return $department;
        });
    }
}
