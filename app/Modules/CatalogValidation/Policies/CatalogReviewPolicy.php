<?php

namespace App\Modules\CatalogValidation\Policies;

use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Core\Queries\ScopedContext;

/**
 * Quién revisa los catálogos de un departamento, según docs/dominio/reglas-de-negocio.md, "Validación de
 * catálogos" y "Aprobaciones y permisos" (fila «Validar catálogos del departamento»).
 *
 * - El jefe de departamento, que es de todo el Tec: en las revisiones generales, si cubre el departamento; en
 *   las de salones, si además su alcance vale en el campus de la revisión (D2).
 * - Corrige materias, carreras y planes (CV1) y, solo si el perfil del campus de la revisión lo permite
 *   (`uses_rooms` y `heads_edit_rooms`), sus salones (CV2); confirmado el 2 de noviembre de 2026.
 * - Corregir, validar, reabrir y poner observaciones: solo ese jefe; ni el admin ni Subdirección (CV-S1,
 *   supuesto por confirmar).
 * - Ver la revisión y sus observaciones, y marcar una observación como resuelta: ese jefe, el admin y
 *   Subdirección (CV-S1, supuesto por confirmar).
 *
 * El estado de la revisión (corregir y observar mientras no está validada, reabrir una validada) lo hacen
 * cumplir las Actions. Que ninguna carga del departamento haya salido de borrador al reabrir lo veta
 * AcademicLoad con CatalogReviewReopening. Sin `delete`: una revisión no se borra.
 */
final class CatalogReviewPolicy
{
    use UsesScopedContext;

    public function view(User $user, CatalogReview $review): bool
    {
        return $this->covers($this->scope($user), [Role::Subdireccion, Role::JefeDepartamento], $review);
    }

    /**
     * Corregir los registros de la revisión con la Action de su módulo dueño (CorrectRecord).
     */
    public function correct(User $user, CatalogReview $review): bool
    {
        if (! $this->isHead($this->scope($user), $review)) {
            return false;
        }

        return $review->catalog->isGeneral() || ($review->campus instanceof Campus && $review->campus->profile->usesRooms && $review->campus->profile->headsEditRooms);
    }

    public function validate(User $user, CatalogReview $review): bool
    {
        return $this->isHead($this->scope($user), $review);
    }

    public function reopen(User $user, CatalogReview $review): bool
    {
        return $this->isHead($this->scope($user), $review);
    }

    /**
     * Poner una observación sobre un registro de la revisión.
     */
    public function flag(User $user, CatalogReview $review): bool
    {
        return $this->isHead($this->scope($user), $review);
    }

    /**
     * Marcar como resuelta una observación de la revisión.
     */
    public function resolveFlag(User $user, CatalogReview $review): bool
    {
        return $this->view($user, $review);
    }

    /**
     * El jefe del departamento de la revisión, y no por ser admin.
     */
    private function isHead(ScopedContext $scope, CatalogReview $review): bool
    {
        return ! $scope->isAdmin() && $this->covers($scope, [Role::JefeDepartamento], $review);
    }

    /**
     * Una general, en cualquier campus (coversDepartment); la de salones, en su campus (covers). Incluye al admin.
     *
     * @param  list<Role>  $roles
     */
    private function covers(ScopedContext $scope, array $roles, CatalogReview $review): bool
    {
        return $review->campus_id === null
            ? $scope->coversDepartment($roles, $review->department_id)
            : $scope->covers($roles, $review->campus_id, department: $review->department_id);
    }
}
