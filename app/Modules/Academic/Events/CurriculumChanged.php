<?php

namespace App\Modules\Academic\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;

/**
 * Cambió algo que entra en el avance de los alumnos: la retícula de un plan (sus materias, su
 * especialidad o su seriación), sus créditos totales, o los créditos o el tipo de una materia. Lleva
 * el plan afectado, la materia o los dos, y al menos uno. Sale después del commit; Students lo
 * escucha y encola el recálculo de `student_progress` (Academic no depende de Students).
 *
 * Lleva ids y no modelos: quien lo escucha no necesita más, y así no se serializa nada.
 */
final class CurriculumChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @throws InvalidArgumentException si no trae ni el plan ni la materia
     */
    public function __construct(
        public ?string $studyPlanId = null,
        public ?string $subjectId = null,
    ) {
        if ($studyPlanId === null && $subjectId === null) {
            throw new InvalidArgumentException('CurriculumChanged necesita el plan, la materia o los dos.');
        }
    }
}
