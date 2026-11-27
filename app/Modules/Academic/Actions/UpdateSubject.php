<?php

namespace App\Modules\Academic\Actions;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Events\CurriculumChanged;
use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Academic\Events\SubjectUpdated;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Rules\SubjectRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos o el estado (activar o desactivar) de una materia: solo las claves que recibe.
 * Quién puede lo decide SubjectPolicy antes de llamarla. Hace cumplir las mismas reglas que
 * CreateSubject (SubjectRules); conservar la clave propia vale.
 *
 * En una transacción vuelve a leer la materia con bloqueo (`lockForUpdate`), así el valor anterior de
 * la bitácora es el confirmado aunque `$subject` llegue viejo, y lo deja en `$subject`. Si nada
 * cambió, no escribe, no registra ni avisa.
 *
 * Desactivarla despacha SubjectDeactivating antes de guardar, dentro de la transacción: si un módulo
 * de la derecha la veta (Students, porque hay alumnos que la necesitan), rechaza con sus motivos en
 * `is_active` y no escribe nada. Si pasa, guarda, deja en la bitácora el valor anterior y el nuevo de
 * cada campo y, después del commit, avisa con SubjectUpdated y, si cambiaron los créditos o el tipo,
 * que son lo que entra en el avance de los alumnos, con CurriculumChanged.
 */
final class UpdateSubject
{
    /** Lo que se edita y se registra en la bitácora. */
    public const array FIELDS = ['code', 'name', 'department_id', 'theory_hours', 'practice_hours', 'credits', 'kind', 'needs_lab', 'is_active'];

    /** Lo que cambia el avance de los alumnos (PlanGridQuery lee los créditos y el tipo). */
    private const array CURRICULUM_FIELDS = ['credits', 'kind'];

    public function __construct(private readonly SubjectRules $rules) {}

    /**
     * @param  array{code?: string, name?: string, department_id?: string, theory_hours?: int, practice_hours?: int, credits?: int, kind?: SubjectKind|string|null, needs_lab?: bool, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function handle(Subject $subject, array $data): Subject
    {
        $data = Arr::only($data, self::FIELDS);

        if (array_key_exists('code', $data)) {
            $data['code'] = $this->rules->normalizeCode($data['code']);
        }

        if (array_key_exists('kind', $data)) {
            $data['kind'] = $this->rules->kind($data['kind']);
        }

        $this->rules->ensureNotNegative($data);

        return DB::transaction(function () use ($subject, $data): Subject {
            $subject->setRawAttributes($subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey())->getAttributes(), sync: true);
            $old = $subject->only(self::FIELDS);

            if (array_key_exists('code', $data)) {
                $this->rules->ensureCodeIsFree($data['code'], $subject);
            }

            if (array_key_exists('department_id', $data) && $data['department_id'] !== $subject->department_id) {
                $this->rules->ensureDepartmentExists($data['department_id']);
            }

            $subject->fill($data);
            $changed = array_keys($subject->getDirty());

            if ($changed === []) {
                return $subject;
            }

            if (in_array('is_active', $changed, true) && ! $subject->is_active) {
                $this->ensureNotVetoed($subject);
            }

            try {
                $subject->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->codeTaken($subject->code);
            }

            activity()
                ->performedOn($subject)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $subject->only($changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó la materia {$subject->code}");

            SubjectUpdated::dispatch($subject, $changed);

            if (array_intersect($changed, self::CURRICULUM_FIELDS) !== []) {
                CurriculumChanged::dispatch(subjectId: $subject->id);
            }

            return $subject;
        });
    }

    /**
     * Pregunta con SubjectDeactivating si alguien necesita la materia. Con veto, deja `$subject` como
     * estaba en la base y rechaza con los motivos en `is_active`.
     *
     * @throws ValidationException
     */
    private function ensureNotVetoed(Subject $subject): void
    {
        $event = new SubjectDeactivating($subject);
        event($event);

        if ($event->blocked()) {
            $subject->discardChanges();

            throw ValidationException::withMessages(['is_active' => $event->reasons()]);
        }
    }
}
