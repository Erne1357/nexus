<?php

namespace App\Modules\Academic\Rules;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de la seriación de un plan (docs/dominio/modelo-de-datos.md, `subject_prerequisites`;
 * reglas-de-negocio.md, "Reglas de datos" y "Seriación del plan"), que comparten AddPrerequisite,
 * UpdatePrerequisite y la plantilla `prerequisites` de Imports. Cada regla rota es una ValidationException sobre
 * su campo.
 *
 * - `subject_id` y `prerequisite_subject_id`: las dos materias tienen su fila sin quitar en la retícula del plan,
 *   y siguen en el catálogo.
 * - `prerequisite_subject_id`: una materia no se pide a sí misma.
 * - `kind`: una seriación no cierra un ciclo, es decir, su requisito no pide ya, en cadena, a la materia. Los
 *   correquisitos no bloquean, así que no cuentan para el ciclo ni lo cierran.
 *
 * Las aristas de la seriación van de cada materia a las que pide en seriación: `[materia => [requisito => true]]`.
 */
final class SeriationRules
{
    /**
     * La seriación sin quitar del plan, sin correquisitos, como aristas.
     *
     * @return array<string, array<string, true>>
     */
    public static function edgesFor(string $studyPlanId): array
    {
        $edges = [];

        foreach (SubjectPrerequisite::query()->where('study_plan_id', $studyPlanId)->where('kind', PrerequisiteKind::Seriacion)->toBase()->get(['subject_id', 'prerequisite_subject_id']) as $edge) {
            $edges[$edge->subject_id][$edge->prerequisite_subject_id] = true;
        }

        return $edges;
    }

    /**
     * Si `$subjectId` pidiendo `$prerequisiteId` en seriación cierra un ciclo: el requisito ya pide, en cadena, a
     * la materia, o son la misma. Pura: solo mira `$edges`.
     *
     * @param  array<string, array<string, true>>  $edges
     */
    public static function closesCycle(array $edges, string $subjectId, string $prerequisiteId): bool
    {
        return self::cyclePath($edges, $subjectId, $prerequisiteId) !== null;
    }

    /**
     * La cadena más corta por la que `$prerequisiteId` ya pide a `$subjectId`, del requisito a la materia (cada
     * paso pide al siguiente); `[$subjectId]` si son la misma, y null si no cierra un ciclo. Pura: solo mira
     * `$edges`, y termina aunque ya tengan un ciclo.
     *
     * @param  array<string, array<string, true>>  $edges
     * @return list<string>|null
     */
    public static function cyclePath(array $edges, string $subjectId, string $prerequisiteId): ?array
    {
        $previous = [$prerequisiteId => null];
        $pending = [$prerequisiteId];

        for ($next = 0; $next < count($pending); $next++) {
            $current = $pending[$next];

            if ($current === $subjectId) {
                $path = [];

                for ($step = $current; $step !== null; $step = $previous[$step]) {
                    array_unshift($path, $step);
                }

                return $path;
            }

            foreach (array_keys($edges[$current] ?? []) as $required) {
                if (! array_key_exists($required, $previous)) {
                    $previous[$required] = $current;
                    $pending[] = $required;
                }
            }
        }

        return null;
    }

    /**
     * El plan como está en la base, bloqueado para escribir (`FOR UPDATE`): dos sesiones que agregan seriación al
     * mismo plan se esperan, así cada una revisa el ciclo con la seriación que dejó la otra.
     */
    public function lockPlan(string $planId): StudyPlan
    {
        return StudyPlan::query()->lockForUpdate()->findOrFail($planId);
    }

    /**
     * Bloquea para compartir (`FOR SHARE`) las filas de la retícula de las dos materias: así RemovePlacement no
     * quita ninguna hasta que la Action termine, ni la Action escribe una seriación sobre una fila que se quitó.
     * Solo bloquea esas filas (`FOR SHARE OF study_plan_subjects`): las materias del catálogo se leen sin bloqueo
     * para saber si están borradas, así editar o desactivar una materia no espera a la seriación. Las Actions lo
     * llaman antes de bloquear la seriación, en el mismo orden que RemovePlacement (la fila, luego su seriación).
     *
     * @throws ValidationException sobre `subject_id` o `prerequisite_subject_id` si una no está en la retícula
     */
    public function lockPlacements(StudyPlan $plan, Subject $subject, Subject $prerequisite): void
    {
        $placed = StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->whereNull('subjects.deleted_at')
            ->where('study_plan_subjects.study_plan_id', $plan->getKey())
            ->whereIn('study_plan_subjects.subject_id', [$subject->getKey(), $prerequisite->getKey()])
            ->lock('for share of study_plan_subjects')
            ->pluck('study_plan_subjects.subject_id')
            ->all();

        foreach (['subject_id' => $subject, 'prerequisite_subject_id' => $prerequisite] as $field => $placement) {
            if (! in_array($placement->getKey(), $placed, true)) {
                throw ValidationException::withMessages([$field => "{$placement->code} no está en la retícula de este plan."]);
            }
        }
    }

    /**
     * @throws ValidationException sobre `prerequisite_subject_id`
     */
    public function ensureNotItself(Subject $subject, Subject $prerequisite): void
    {
        if ($subject->getKey() === $prerequisite->getKey()) {
            throw ValidationException::withMessages(['prerequisite_subject_id' => "{$subject->code} no puede pedirse a sí misma."]);
        }
    }

    /**
     * Revisa contra la seriación del plan en la base que `$subject` pidiendo `$prerequisite` en seriación no
     * cierre un ciclo.
     *
     * @throws ValidationException sobre `kind`: como correquisito sí se puede
     */
    public function ensureNoCycle(StudyPlan $plan, Subject $subject, Subject $prerequisite): void
    {
        if (self::closesCycle(self::edgesFor($plan->getKey()), $subject->getKey(), $prerequisite->getKey())) {
            throw ValidationException::withMessages(['kind' => "Cierra un ciclo: {$prerequisite->code} ya pide, en cadena, a {$subject->code}."]);
        }
    }
}
