<?php

namespace App\Modules\Academic\Rules;

use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Queries\KeyLookup;
use Illuminate\Validation\ValidationException;

/**
 * Reglas del lugar de una materia en la retícula de un plan que comparten PlaceSubject y UpdatePlacement
 * (docs/dominio/modelo-de-datos.md, `study_plan_subjects`; reglas-de-negocio.md, "Retícula del plan"). Cada
 * regla rota es una ValidationException sobre su campo.
 *
 * - `semester`: de 1 a `semesters_count` del plan; si el plan no los tiene capturados, de 1 a 12 (confirmado el 29 de octubre de 2026).
 * - `subject_id`: una materia del catálogo, sin borrar y activa (confirmado el 29 de octubre de 2026), que todavía no está en
 *   la retícula del plan.
 * - `specialty_id`: una especialidad del mismo plan, sin quitar y activa (confirmado el 29 de octubre de 2026); null es el
 *   tronco común.
 *
 * Lo que lee para decidir lo lee dentro de la transacción de la Action y lo bloquea para compartir
 * (`FOR SHARE`): así UpdateStudyPlan no cambia los semestres, UpdateSubject no desactiva la materia y
 * RemoveSpecialty no quita la especialidad hasta que la Action termine, y la Action espera a que terminen ellas.
 */
final class PlacementRules
{
    /** Hasta qué semestre se pone una materia en un plan sin semestres capturados (confirmado el 29 de octubre de 2026). */
    public const int SEMESTERS_WITHOUT_COUNT = 12;

    /** El error de una especialidad que no es del plan, que se quitó o con un id que no es uuid. */
    public const string SPECIALTY_GONE = 'La especialidad no es de este plan o se quitó.';

    /**
     * Los semestres que el editor ofrece para poner o mover una materia: los del plan o, si no están capturados,
     * los que ya tienen materias y uno más, hasta SEMESTERS_WITHOUT_COUNT.
     */
    public static function offeredSemesters(?int $semestersCount, int $lastSemesterWithSubjects): int
    {
        return $semestersCount ?? max(1, min(self::SEMESTERS_WITHOUT_COUNT, $lastSemesterWithSubjects + 1));
    }

    /**
     * El plan como está en la base, bloqueado para compartir.
     */
    public function lockPlan(string $planId): StudyPlan
    {
        return StudyPlan::query()->sharedLock()->findOrFail($planId);
    }

    /**
     * @throws ValidationException sobre `semester`
     */
    public function ensureSemesterIsValid(StudyPlan $plan, int $semester): void
    {
        $last = $plan->semesters_count ?? self::SEMESTERS_WITHOUT_COUNT;

        if ($semester >= 1 && $semester <= $last) {
            return;
        }

        throw ValidationException::withMessages(['semester' => $plan->semesters_count === null
            ? "El semestre debe ser de 1 a {$last} mientras el plan no tenga capturados sus semestres."
            : "El semestre debe ser de 1 a {$last}: el plan tiene {$last} semestres.",
        ]);
    }

    /**
     * La materia como está en la base, bloqueada para compartir, si se puede poner: sin borrar y activa.
     *
     * @throws ValidationException sobre `subject_id`
     */
    public function lockSubject(Subject $subject): Subject
    {
        $subject = Subject::withTrashed()->sharedLock()->findOrFail($subject->getKey());

        if ($subject->trashed()) {
            throw ValidationException::withMessages(['subject_id' => "{$subject->code} está borrada del catálogo."]);
        }

        if (! $subject->is_active) {
            throw ValidationException::withMessages(['subject_id' => "{$subject->code} está inactiva en el catálogo: actívala antes de ponerla en una retícula."]);
        }

        return $subject;
    }

    /**
     * La especialidad `$id` del plan, bloqueada para compartir, si se le pueden poner materias: sin quitar y
     * activa. null es el tronco común.
     *
     * @throws ValidationException sobre `specialty_id`
     */
    public function lockSpecialty(StudyPlan $plan, ?string $id): ?Specialty
    {
        if ($id === null) {
            return null;
        }

        $specialty = KeyLookup::find(Specialty::query()->where('study_plan_id', $plan->getKey())->sharedLock(), $id)
            ?? throw ValidationException::withMessages(['specialty_id' => self::SPECIALTY_GONE]);

        if (! $specialty->is_active) {
            throw ValidationException::withMessages(['specialty_id' => "La especialidad {$specialty->code} está inactiva: actívala antes de ponerle materias."]);
        }

        return $specialty;
    }

    /**
     * El error de una materia que ya está en la retícula; sin semestre cuando otra sesión la puso al mismo
     * tiempo y la restricción única ganó la carrera.
     */
    public function alreadyPlaced(Subject $subject, ?int $semester = null): ValidationException
    {
        return ValidationException::withMessages([
            'subject_id' => "{$subject->code} ya está en la retícula de este plan".($semester === null ? '.' : ", en el semestre {$semester}."),
        ]);
    }
}
