<?php

namespace App\Modules\Faculty\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Collection;

/**
 * Quién administra docentes, según reglas-de-negocio.md, "Aprobaciones y permisos" (filas "Administrar docentes" y
 * "Dar de baja o reactivar a un docente"). Los docentes son de todo el Tec: la adscripción es solo de un departamento
 * y nada de esto depende de un campus.
 *
 * Confirmado el 30 de octubre de 2026:
 * - El admin y la Subdirección (una para todo el Tec), a todos los docentes, también a los que no tienen departamento.
 * - El jefe de departamento de D (de todo el Tec) y el jefe de proyecto docente de D, a los docentes con una
 *   adscripción sin borrar a D, aunque su principal sea otra: ver, editar sus datos, plazas, disponibilidad, horas
 *   fuera del ITCJ, quitarlos de D y su perfil, este solo en las materias que imparte un departamento que cubre.
 * - La baja (desactivar o reactivar; desactiva su usuario y termina sus jefaturas), solo el admin y la Subdirección.
 *
 * Supuestos por confirmar:
 * - El jefe de proyecto docente, cuyo alcance lleva campus, administra a todos los docentes de su departamento,
 *   porque el docente no es de un campus.
 * - Nadie se da de baja ni se quita a sí mismo de un departamento, ni el admin.
 * - Dar de alta (`create`) en un departamento es de quien lo cubre; vale también para adscribir a un docente que ya
 *   existe (S9).
 * - Nombrar jefes (S7), dar de alta usuarios y reiniciar contraseñas, solo el admin.
 *
 * No hay `delete`: un docente se da de baja. TeacherQuery lista con la misma regla, en SQL; si cambia aquí, cambia
 * allá.
 */
final class TeacherPolicy
{
    use UsesScopedContext;

    /** Los roles que administran docentes; la Subdirección, a todos, y los jefes, a los de su departamento. */
    public const array EDITORS = [Role::Subdireccion, Role::JefeDepartamento, Role::JefeProyectoDocente];

    public function viewAny(User $user): bool
    {
        $scope = $this->scope($user);

        return array_any(self::EDITORS, fn (Role $role): bool => $scope->has($role));
    }

    public function view(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->scope($user), $teacher);
    }

    /**
     * Sus datos: nombre, correo, grado y fecha de ingreso.
     */
    public function update(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->scope($user), $teacher);
    }

    /**
     * Darlo de baja o reactivarlo: desactiva su usuario y termina sus jefaturas, en todo el Tec.
     */
    public function deactivate(User $user, Teacher $teacher): bool
    {
        return $this->coversEveryone($this->scope($user)) && ! $this->isSelf($user, $teacher->user_id);
    }

    public function managePositions(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->scope($user), $teacher);
    }

    public function manageAvailability(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->scope($user), $teacher);
    }

    /**
     * Sus horas fuera del ITCJ (`teacher_external_loads`).
     */
    public function manageExternalLoads(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->scope($user), $teacher);
    }

    /**
     * Su perfil en una materia: la materia es de un departamento que cubre.
     */
    public function manageQualification(User $user, Teacher $teacher, Subject $subject): bool
    {
        $scope = $this->scope($user);

        return $this->coversTeacher($scope, $teacher) && $scope->coversDepartment(self::EDITORS, $subject->department_id);
    }

    /**
     * «Quitar de mi departamento». Gate busca la policy por el modelo, y TeacherDepartment no tiene una propia: se
     * pregunta con `can('removeAdscription', [Teacher::class, $adscription])`.
     */
    public function removeAdscription(User $user, TeacherDepartment $adscription): bool
    {
        $teacherUserId = $adscription->relationLoaded('teacher')
            ? $adscription->teacher?->user_id
            : Teacher::withTrashed()->whereKey($adscription->teacher_id)->value('user_id');

        return $this->scope($user)->coversDepartment(self::EDITORS, $adscription->department_id) && ! $this->isSelf($user, $teacherUserId);
    }

    /**
     * Sin destino, si da de alta docentes en algún departamento; con el departamento de la adscripción nueva, si lo
     * cubre.
     */
    public function create(User $user, Department|string|null $department = null): bool
    {
        if ($department === null) {
            return $this->viewAny($user);
        }

        return $this->scope($user)->coversDepartment(self::EDITORS, $department instanceof Department ? $department->getKey() : $department);
    }

    public function appointHeads(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * «Dar de alta usuarios»: sincronizar el acceso de todos los docentes (ProvisionTeacherUsersJob).
     */
    public function provisionUsers(User $user): bool
    {
        return $this->scope($user)->isAdmin();
    }

    public function resetPassword(User $user, Teacher $teacher): bool
    {
        return $this->scope($user)->isAdmin();
    }

    /**
     * El admin y la Subdirección, a todos los docentes, también a los que no tienen departamento.
     */
    private function coversEveryone(ScopedContext $scope): bool
    {
        return $scope->isAdmin() || $scope->has(Role::Subdireccion);
    }

    private function coversTeacher(ScopedContext $scope, Teacher $teacher): bool
    {
        return $this->coversEveryone($scope)
            || $this->adscriptionsOf($teacher)->contains(fn (TeacherDepartment $adscription): bool => $scope->coversDepartment(self::EDITORS, $adscription->department_id));
    }

    private function isSelf(User $user, int|string|null $teacherUserId): bool
    {
        return $teacherUserId !== null && $teacherUserId === $user->getKey();
    }

    /**
     * Sus adscripciones sin borrar: las ya cargadas (TeacherQuery las trae con el docente) o, si no, de la base.
     *
     * @return Collection<int, TeacherDepartment>
     */
    private function adscriptionsOf(Teacher $teacher): Collection
    {
        return $teacher->relationLoaded('adscriptions')
            ? $teacher->adscriptions
            : $teacher->adscriptions()->get(['id', 'teacher_id', 'department_id']);
    }
}
