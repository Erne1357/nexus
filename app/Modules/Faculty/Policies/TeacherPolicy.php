<?php

namespace App\Modules\Faculty\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\UsesScopedContext;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Collection;

/**
 * Quién administra docentes, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Administrar docentes",
 * confirmada el 30 de octubre de 2026):
 *
 * - El admin, a todos.
 * - El jefe de departamento y el jefe de proyecto docente de D en C, a los docentes con una adscripción sin borrar a
 *   D en C, aunque su principal sea otra.
 * - La subdirección de C, a los adscritos en C, de cualquier departamento.
 *
 * Nadie más: ni el coordinador ni el docente. Ver, editar, dar de baja, plazas, disponibilidad y horas fuera del
 * ITCJ piden lo mismo: que ScopedContext::covers() cubra alguna de sus adscripciones. El perfil (`manageQualification`)
 * pide además que la materia sea de un departamento que cubre en el campus de una de esas adscripciones; quitar una
 * adscripción, que cubra esa misma (S8, supuesto por confirmar). Dar de alta (`create`) es de quien cubre el
 * departamento y el campus de la adscripción nueva, también para adscribir a uno que ya existe (S9). Nombrar jefes
 * (S7), dar de alta usuarios y reiniciar contraseñas, solo el admin. No hay `delete`: un docente se da de baja.
 *
 * TeacherQuery lista con la misma regla, en SQL; si cambia aquí, cambia allá.
 */
final class TeacherPolicy
{
    use UsesScopedContext;

    /** Los roles que administran a los docentes de su alcance; el admin, a todos. */
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
     * Darlo de baja o reactivarlo.
     */
    public function deactivate(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->scope($user), $teacher);
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
     * Su perfil en una materia: el jefe solo en las materias que imparte su departamento (confirmado el 30 de
     * octubre de 2026).
     */
    public function manageQualification(User $user, Teacher $teacher, Subject $subject): bool
    {
        $scope = $this->scope($user);

        if ($scope->isAdmin()) {
            return true;
        }

        $adscriptions = $this->adscriptionsOf($teacher);

        return $this->coversAny($scope, $adscriptions)
            && $adscriptions->contains(fn (TeacherDepartment $adscription): bool => $scope->covers(self::EDITORS, $adscription->campus_id, department: $subject->department_id));
    }

    /**
     * Gate busca la policy por el modelo, y TeacherDepartment no tiene una propia: se pregunta con
     * `can('removeAdscription', [Teacher::class, $adscription])`.
     */
    public function removeAdscription(User $user, TeacherDepartment $adscription): bool
    {
        return $this->scope($user)->covers(self::EDITORS, $adscription->campus_id, department: $adscription->department_id);
    }

    /**
     * Sin destino, si da de alta docentes en algún lado; con el campus y el departamento de la adscripción nueva,
     * si los cubre.
     */
    public function create(User $user, Campus|string|null $campus = null, Department|string|null $department = null): bool
    {
        if ($campus === null) {
            return $this->viewAny($user);
        }

        return $this->scope($user)->covers(self::EDITORS, $campus, department: $department instanceof Department ? $department->getKey() : $department);
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

    private function coversTeacher(ScopedContext $scope, Teacher $teacher): bool
    {
        return $scope->isAdmin() || $this->coversAny($scope, $this->adscriptionsOf($teacher));
    }

    /**
     * @param  Collection<int, TeacherDepartment>  $adscriptions
     */
    private function coversAny(ScopedContext $scope, Collection $adscriptions): bool
    {
        return $adscriptions->contains(fn (TeacherDepartment $adscription): bool => $scope->covers(self::EDITORS, $adscription->campus_id, department: $adscription->department_id));
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
            : $teacher->adscriptions()->get(['id', 'teacher_id', 'department_id', 'campus_id']);
    }
}
