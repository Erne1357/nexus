<?php

namespace App\Modules\CatalogValidation\Policies;

use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién revisa los catálogos de un departamento, según docs/dominio/reglas-de-negocio.md, "Validación de
 * catálogos" y "Aprobaciones y permisos" (fila «Validar catálogos del departamento»).
 *
 * - El jefe de departamento, que es de todo el Tec, si cubre el departamento. Las revisiones no son de un campus,
 *   también la de salones (decisión 6, confirmada el 12 de noviembre de 2026).
 * - Corrige materias, carreras y planes (CV1) y los salones de su departamento en cualquier campus (CV2, confirmado
 *   el 12 de noviembre de 2026; ya no depende de `heads_edit_rooms`). Los docentes, en su correo institucional
 *   (CV1); quién cambia ese correo lo decide además TeacherPolicy::updateInstitutionalEmail (AC-06).
 * - Corregir, validar, reabrir y poner observaciones: solo ese jefe; ni el admin ni Subdirección (CV-S1,
 *   supuesto por confirmar).
 * - Marcar y desmarcar registros como revisados (CV7): quien valida, con el mismo permiso, porque sin todos revisados
 *   no se valida.
 * - Ver el tablero de avance de validación y recordar a los jefes sus pendientes (tarea 2.5): solo Subdirección y
 *   el admin; los jefes, coordinadores y demás roles no (supuesto por confirmar).
 * - Ver la revisión y sus observaciones, y marcar una observación como resuelta: ese jefe, el admin y
 *   Subdirección (CV-S1, supuesto por confirmar).
 *
 * Se resuelve con los permisos de CatalogValidationPermission (`catalog_validation.review.*` por campus y
 * departamento; las revisiones no son de un campus: las cubre cualquier nombramiento del departamento, sea cual sea su
 * campus), que el provider concede por puesto; corregir, validar, reabrir y observar no se conceden al admin
 * (adminDenies).
 *
 * El estado de la revisión (corregir y observar mientras no está validada, reabrir una validada) lo hacen
 * cumplir las Actions. Que ninguna carga del departamento haya salido de borrador al reabrir lo veta
 * AcademicLoad con CatalogReviewReopening. Sin `delete`: una revisión no se borra.
 */
final class CatalogReviewPolicy
{
    use AuthorizesActor;

    /**
     * El tablero de avance de validación por departamento y campus (tarea 2.5).
     */
    public function viewProgress(User $user): bool
    {
        return $this->actor($user)->allows(CatalogValidationPermission::ProgressView);
    }

    /**
     * Recordar a los jefes de departamento que tienen catálogos por validar (RemindPendingReviews).
     */
    public function remind(User $user): bool
    {
        return $this->actor($user)->allows(CatalogValidationPermission::ProgressRemind);
    }

    public function view(User $user, CatalogReview $review): bool
    {
        return $this->can($user, CatalogValidationPermission::ReviewView, $review);
    }

    /**
     * Corregir los registros de la revisión con la Action de su módulo dueño (CorrectRecord).
     */
    public function correct(User $user, CatalogReview $review): bool
    {
        return $this->can($user, CatalogValidationPermission::ReviewCorrect, $review);
    }

    public function validate(User $user, CatalogReview $review): bool
    {
        return $this->can($user, CatalogValidationPermission::ReviewValidate, $review);
    }

    /**
     * Marcar o desmarcar registros de la revisión como revisados (MarkRecordsReviewed, UnmarkRecordReviewed; CV7).
     */
    public function mark(User $user, CatalogReview $review): bool
    {
        return $this->can($user, CatalogValidationPermission::ReviewValidate, $review);
    }

    public function reopen(User $user, CatalogReview $review): bool
    {
        return $this->can($user, CatalogValidationPermission::ReviewReopen, $review);
    }

    /**
     * Poner una observación sobre un registro de la revisión.
     */
    public function flag(User $user, CatalogReview $review): bool
    {
        return $this->can($user, CatalogValidationPermission::ReviewFlag, $review);
    }

    /**
     * Marcar como resuelta una observación de la revisión.
     */
    public function resolveFlag(User $user, CatalogReview $review): bool
    {
        return $this->can($user, CatalogValidationPermission::ReviewResolveFlag, $review);
    }

    private function can(User $user, CatalogValidationPermission $permission, CatalogReview $review): bool
    {
        return CatalogReviewQuery::allowsOnDepartment($this->actor($user), $permission, $review->campus_id, $review->department_id);
    }
}
