<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\UpdateTeacher;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El correo institucional de un docente desde su fila en la revisión de docentes (CV1, AC-06; captura rápida, D4 y
 * D11, confirmados el 14 de noviembre de 2026), con las reglas de AC-06 que hace cumplir UpdateTeacher: del dominio
 * institucional, único entre todos los docentes. Quién puede lo deciden CatalogReviewPolicy::correct y
 * TeacherPolicy::updateInstitutionalEmail antes de llamarla.
 *
 * - Con la revisión abierta es corregir el registro (CorrectRecord): queda revisado aunque el correo no cambie (CV7,
 *   CV-S7) y la revisión pasa a en revisión (CV-S4).
 * - Con la revisión validada se sigue capturando (excepción de CV-S2): solo cambia el correo, la revisión sigue
 *   validada y sin marca nueva, y la invitación sale como desde la ficha (InviteTeacherAfterValidation, AC-09).
 *
 * En una transacción relee la revisión con bloqueo (así no se cruza con ValidateReview ni con ReopenReview) y exige que
 * el docente sea de la revisión, que tiene que ser la de docentes.
 */
final class SetReviewTeacherEmail
{
    public function __construct(
        private readonly CatalogReviewRules $rules,
        private readonly CorrectRecord $correctRecord,
        private readonly UpdateTeacher $updateTeacher,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, Teacher $teacher, ?string $email, User $actor): Teacher
    {
        if ($review->catalog !== Catalog::Teachers) {
            throw ValidationException::withMessages(['record' => 'El registro no es de esta revisión.']);
        }

        return DB::transaction(function () use ($review, $teacher, $email, $actor): Teacher {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            if ($review->status !== ReviewStatus::Validated) {
                $this->correctRecord->handle($review, $teacher, ['institutional_email' => $email], $actor);

                return $teacher;
            }

            $this->rules->ensureContains($review, $teacher);

            return $this->updateTeacher->handle($teacher, ['institutional_email' => $email]);
        });
    }
}
