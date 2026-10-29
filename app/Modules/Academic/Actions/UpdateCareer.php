<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CareerUpdated;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Rules\CareerRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia la clave, el nombre, el departamento o el estado (activar o desactivar) de una carrera:
 * solo las claves que recibe. Quién puede lo decide CareerPolicy antes de llamarla. Hace cumplir las
 * mismas reglas que CreateCareer (CareerRules); conservar la clave propia vale, y el departamento
 * solo se revisa si cambia (uno que se borró después de dar de alta la carrera no estorba).
 *
 * En una transacción vuelve a leer la carrera con bloqueo (`lockForUpdate`), así el valor anterior
 * de la bitácora es el confirmado aunque `$career` llegue vieja, y la deja en `$career`. Si nada
 * cambió, no escribe, no registra ni avisa; si algo cambió, guarda, deja en la bitácora el valor
 * anterior y el nuevo de cada campo y, después del commit, avisa con CareerUpdated.
 */
final class UpdateCareer
{
    public function __construct(private readonly CareerRules $rules) {}

    /**
     * @param  array{code?: string, name?: string, department_id?: string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Career $career, array $data): Career
    {
        $data = Arr::only($data, ['code', 'name', 'department_id', 'is_active']);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        return DB::transaction(function () use ($career, $data): Career {
            $career->setRawAttributes($career->newQuery()->lockForUpdate()->findOrFail($career->getKey())->getAttributes(), sync: true);
            $old = $career->only(['code', 'name', 'department_id', 'is_active']);

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($data['code'], $career);
            }

            if (array_key_exists('department_id', $data) && $data['department_id'] !== $career->department_id) {
                $data['department_id'] = $this->rules->department($data['department_id'])->id;
            }

            $career->fill($data);
            $changed = array_keys($career->getDirty());

            if ($changed === []) {
                return $career;
            }

            try {
                $career->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($career->code);
            }

            activity()
                ->performedOn($career)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $career->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó la carrera {$career->code}");

            CareerUpdated::dispatch($career, $changed);

            return $career;
        });
    }
}
