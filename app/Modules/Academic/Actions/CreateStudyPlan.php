<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\StudyPlanCreated;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Rules\StudyPlanRules;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Da de alta un plan de estudios activo. Recibe datos ya validados en forma (el componente) y hace
 * cumplir las reglas de StudyPlanRules: clave normalizada y única también entre los borrados,
 * semestres mayores que cero o vacíos, créditos no negativos o vacíos y una carrera que existe. Una
 * regla rota es una ValidationException sobre su campo.
 *
 * En una transacción revisa la clave y la carrera, escribe y deja bitácora; si otra sesión guarda la
 * misma clave entre la revisión y el insert, la restricción única responde con el mismo error.
 * Después del commit avisa con StudyPlanCreated; sin retícula ni alumnos, no cambia el avance de
 * nadie.
 */
final class CreateStudyPlan
{
    public function __construct(private readonly StudyPlanRules $rules) {}

    /**
     * @param  array{career_id: string, code: string, name: string, version: string, semesters_count?: ?int, total_credits?: ?int, valid_from?: CarbonInterface|string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data): StudyPlan
    {
        $code = $this->rules->normalizeCode($data['code']);
        $this->rules->ensureTextIsPresent($data, required: true);
        $this->rules->ensureNumbersAreValid($data);

        return DB::transaction(function () use ($code, $data): StudyPlan {
            $this->rules->ensureCodeIsFree($code);
            $career = $this->rules->career($data['career_id']);

            try {
                $plan = StudyPlan::create([
                    'career_id' => $career->id,
                    'code' => $code,
                    'name' => $data['name'],
                    'version' => $data['version'],
                    'semesters_count' => $data['semesters_count'] ?? null,
                    'total_credits' => $data['total_credits'] ?? null,
                    'valid_from' => $data['valid_from'] ?? null,
                    'is_active' => true,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($code);
            }

            activity()
                ->performedOn($plan)
                ->event(ActivityEvent::Created)
                ->withChanges(['attributes' => $plan->only(UpdateStudyPlan::FIELDS)])
                ->log("Dio de alta el plan {$plan->code}");

            StudyPlanCreated::dispatch($plan);

            return $plan;
        });
    }
}
