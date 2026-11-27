<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\PlanCareerChanging;
use App\Modules\Academic\Events\StudyPlanUpdated;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Rules\StudyPlanRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos o el estado (activar o desactivar) de un plan: solo las claves que recibe. Quién
 * puede lo decide StudyPlanPolicy antes de llamarla. Hace cumplir las mismas reglas que
 * CreateStudyPlan (StudyPlanRules); conservar la clave propia vale.
 *
 * En una transacción vuelve a leer el plan con bloqueo (`lockForUpdate`), así el valor anterior de
 * la bitácora es el confirmado aunque `$plan` llegue viejo, y lo deja en `$plan`. Si nada cambió, no
 * escribe, no registra ni avisa.
 *
 * Cambiar la carrera despacha PlanCareerChanging antes de guardar, dentro de la transacción: si un
 * módulo de la derecha la veta (Students, porque hay alumnos con el plan y su carrera cambiaría con
 * él), rechaza con sus motivos en `career_id` y no escribe nada (reglas-de-negocio.md, plan →
 * carrera). Si pasa, guarda, deja en la bitácora el valor anterior y el nuevo de cada campo y,
 * después del commit, avisa con StudyPlanUpdated y, si cambiaron los créditos totales o la carrera,
 * que entran en el avance de los alumnos, con CurriculumChanged.
 */
final class UpdateStudyPlan
{
    /** Lo que se edita y se registra en la bitácora. */
    public const array FIELDS = ['career_id', 'code', 'name', 'version', 'semesters_count', 'total_credits', 'valid_from', 'is_active'];

    /** Lo que cambia el avance de los alumnos. */
    private const array CURRICULUM_FIELDS = ['total_credits', 'career_id'];

    public function __construct(private readonly StudyPlanRules $rules) {}

    /**
     * @param  array<string, mixed>  $data  claves de FIELDS
     *
     * @throws ValidationException
     */
    public function handle(StudyPlan $plan, array $data): StudyPlan
    {
        $data = Arr::only($data, self::FIELDS);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        $this->rules->ensureTextIsPresent($data);
        $this->rules->ensureNumbersAreValid($data);

        return DB::transaction(function () use ($plan, $data): StudyPlan {
            $plan->setRawAttributes($plan->newQuery()->lockForUpdate()->findOrFail($plan->getKey())->getAttributes(), sync: true);
            $old = $plan->only(self::FIELDS);

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($data['code'], $plan);
            }

            if (array_key_exists('career_id', $data) && $data['career_id'] !== $plan->career_id) {
                $data['career_id'] = $this->rules->career($data['career_id'])->id;
            }

            $plan->fill($data);
            $changed = array_keys($plan->getDirty());

            if ($changed === []) {
                return $plan;
            }

            if (in_array('career_id', $changed, true)) {
                $this->ensureNotVetoed($plan, $old['career_id']);
            }

            try {
                $plan->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($plan->code);
            }

            activity()
                ->performedOn($plan)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $plan->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó el plan {$plan->code}");

            StudyPlanUpdated::dispatch($plan, $changed);

            if (array_intersect($changed, self::CURRICULUM_FIELDS) !== []) {
                CurriculumChanged::dispatch(studyPlanId: $plan->id);
            }

            return $plan;
        });
    }

    /**
     * Pregunta con PlanCareerChanging si alguien depende de la carrera actual. Con veto, deja
     * `$plan` como estaba en la base y rechaza con los motivos en `career_id`.
     *
     * @throws ValidationException
     */
    private function ensureNotVetoed(StudyPlan $plan, string $fromCareerId): void
    {
        $event = new PlanCareerChanging($plan, $fromCareerId);
        event($event);

        if ($event->blocked()) {
            $plan->discardChanges();

            throw ValidationException::withMessages(['career_id' => $event->reasons()]);
        }
    }
}
