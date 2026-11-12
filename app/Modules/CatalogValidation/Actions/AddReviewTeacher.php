<?php

namespace App\Modules\CatalogValidation\Actions;

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Rules\CatalogReviewRules;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Actions\AddAdscription;
use App\Modules\Faculty\Actions\SetTeacherActive;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Agregar docente» en la revisión de docentes (CV-S9, supuesto por confirmar; reglas-de-negocio.md, "Validación de
 * catálogos"): el jefe adscribe a su departamento a un docente que ya existe, activo o dado de baja; al dado de baja
 * primero lo reactiva (SetTeacherActive, que le regresa su usuario), porque solo se adscribe a un docente activo. Su
 * acceso sigue AC-09: la invitación sale al validar la revisión o, si ya estaba validada en otro departamento, al
 * adscribirlo. Quién puede lo deciden CatalogReviewPolicy::correct y TeacherPolicy::adscribeToDepartment antes de llamarla.
 *
 * En una transacción relee la revisión con bloqueo, exige que sea la de docentes y que no esté validada, reactiva si
 * hace falta, lo adscribe (AddAdscription: si no tiene principal, esta lo es; si ya está en el departamento, error en
 * `adscription`), lo marca como revisado (MarkRecordsReviewed, CV7) y la revisión pasa a en revisión (CV-S4). Cada paso
 * deja su bitácora.
 */
final class AddReviewTeacher
{
    public function __construct(
        private readonly CatalogReviewRules $rules,
        private readonly SetTeacherActive $setTeacherActive,
        private readonly AddAdscription $addAdscription,
        private readonly MarkRecordsReviewed $markRecords,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(CatalogReview $review, Teacher $teacher, User $actor): TeacherDepartment
    {
        return DB::transaction(function () use ($review, $teacher, $actor): TeacherDepartment {
            $review->setRawAttributes($review->newQuery()->lockForUpdate()->findOrFail($review->getKey())->getAttributes(), sync: true);

            if ($review->catalog !== Catalog::Teachers) {
                throw ValidationException::withMessages(['record' => 'Los docentes se agregan en la revisión de docentes.']);
            }

            $this->rules->ensureOpen($review);

            $this->setTeacherActive->handle($teacher, true);
            $adscription = $this->addAdscription->handle($teacher, $review->department);
            $this->markRecords->handle($review, [$teacher->getKey()], $actor);

            return $adscription;
        });
    }
}
