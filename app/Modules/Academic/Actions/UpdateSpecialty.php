<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\SpecialtyChanged;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Rules\SpecialtyRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Renombra una especialidad o cambia su estado (activar o desactivar): solo las claves que recibe; la clave
 * no cambia. Desactivar se permite siempre, aunque tenga materias en la retícula (reglas-de-negocio.md,
 * "Especialidades"). Quién puede lo decide StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * En una transacción vuelve a leer la especialidad con bloqueo (`lockForUpdate`), así el valor anterior de
 * la bitácora es el confirmado aunque `$specialty` llegue vieja, y la deja en `$specialty`. Si nada cambió,
 * no escribe, no registra ni avisa; si algo cambió, guarda, deja en la bitácora el valor anterior y el nuevo
 * y, después del commit, avisa con SpecialtyChanged. No cambia el avance de nadie: el avance cuenta las
 * materias por su especialidad, sin mirar su nombre ni su estado.
 */
final class UpdateSpecialty
{
    /** Lo que se edita y se registra en la bitácora. */
    public const array FIELDS = ['name', 'is_active'];

    public function __construct(private readonly SpecialtyRules $rules) {}

    /**
     * @param  array<string, mixed>  $data  claves de FIELDS
     *
     * @throws ValidationException
     */
    public function handle(Specialty $specialty, array $data): Specialty
    {
        $data = Arr::only($data, self::FIELDS);
        $this->rules->ensureNameIsPresent($data);

        return DB::transaction(function () use ($specialty, $data): Specialty {
            $specialty->setRawAttributes($specialty->newQuery()->lockForUpdate()->findOrFail($specialty->getKey())->getAttributes(), sync: true);
            $old = $specialty->only(self::FIELDS);

            $specialty->fill($data);
            $changed = array_keys($specialty->getDirty());

            if ($changed === []) {
                return $specialty;
            }

            $specialty->save();

            activity()
                ->performedOn($specialty)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $specialty->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó la especialidad {$specialty->code} del plan {$specialty->studyPlan()->withTrashed()->value('code')}");

            SpecialtyChanged::dispatch($specialty);

            return $specialty;
        });
    }
}
