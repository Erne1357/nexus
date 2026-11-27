<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CareerCreated;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Rules\CareerRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta una carrera. Recibe datos ya validados en forma (el componente) y hace cumplir
 * CareerRules: clave en mayúsculas, sin espacios y única también entre las borradas, y un
 * departamento que existe y no está borrado. Una regla rota es una ValidationException sobre
 * `code` o `department_id`. La carrera nace activa y sin oferta en ningún campus (SetCareerOffering).
 *
 * En una transacción revisa, escribe y deja bitácora; si otra sesión guarda la misma clave entre la
 * revisión y el insert, la restricción única de la base responde con el mismo error. Después del
 * commit avisa con CareerCreated.
 */
final class CreateCareer
{
    public function __construct(private readonly CareerRules $rules) {}

    /**
     * @param  array{code: string, name: string, department_id: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): Career
    {
        $code = $this->rules->normalizeCode($data['code']);

        return DB::transaction(function () use ($code, $data): Career {
            $this->rules->ensureCodeIsFree($code);
            $department = $this->rules->department($data['department_id']);

            try {
                $career = Career::create(['code' => $code, 'name' => $data['name'], 'department_id' => $department->id, 'is_active' => true]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($career)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $career->only(['code', 'name', 'department_id', 'is_active'])])
                ->log("Dio de alta la carrera {$career->code}");

            CareerCreated::dispatch($career);

            return $career;
        });
    }
}
