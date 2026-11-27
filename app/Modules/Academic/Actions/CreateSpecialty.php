<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\SpecialtyChanged;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Rules\SpecialtyRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta una especialidad activa en un plan. Recibe datos ya validados en forma (el componente) y hace
 * cumplir las reglas de SpecialtyRules: clave normalizada y única dentro del plan, también contra las
 * quitadas, y un nombre. Quién puede lo decide StudyPlanPolicy (`editGrid`) antes de llamarla.
 *
 * En una transacción revisa la clave, escribe y deja bitácora; si otra sesión guarda la misma clave entre
 * la revisión y el insert, la restricción única responde con el mismo error. Después del commit avisa con
 * SpecialtyChanged; sin materias, no cambia el avance de nadie.
 */
final class CreateSpecialty
{
    /** Lo que se registra en la bitácora. */
    public const array FIELDS = ['code', 'name', 'is_active'];

    public function __construct(private readonly SpecialtyRules $rules) {}

    /**
     * @param  array{code: string, name: ?string}  $data
     *
     * @throws ValidationException
     */
    public function handle(StudyPlan $plan, array $data): Specialty
    {
        $code = $this->rules->normalizeCode($data['code']);
        $this->rules->ensureNameIsPresent($data, required: true);

        return DB::transaction(function () use ($plan, $code, $data): Specialty {
            $this->rules->ensureCodeIsFree($plan, $code);

            try {
                $specialty = Specialty::create([
                    'study_plan_id' => $plan->getKey(),
                    'code' => $code,
                    'name' => $data['name'],
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($specialty)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $specialty->only(self::FIELDS)])
                ->log("Dio de alta la especialidad {$specialty->code} del plan {$plan->code}");

            SpecialtyChanged::dispatch($specialty);

            return $specialty;
        });
    }
}
