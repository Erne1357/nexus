<?php

namespace App\Modules\Faculty\Policies;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Faculty\Enums\FacultyPermission;
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
 *   Desde el 12 de noviembre de 2026, quitar a un docente de su última adscripción también lo da de baja (CV8), y a
 *   quien ocupa un puesto solo lo quitan o lo reactivan el admin y la Subdirección (CV-S8, CV-S9).
 *
 * Supuestos por confirmar:
 * - El jefe de proyecto docente, cuyo alcance lleva campus, administra a todos los docentes de su departamento,
 *   porque el docente no es de un campus.
 * - Nadie se da de baja ni se quita a sí mismo de un departamento, ni el admin.
 * - Dar de alta (`create`) en un departamento es de quien lo cubre. Adscribir a un docente que ya existe
 *   (`adscribe`) es solo del admin y la Subdirección; a los jefes la pantalla solo les dice que el número ya está
 *   registrado (S9).
 * - Dar de alta usuarios y reiniciar contraseñas, solo el admin. Reenviar la invitación de acceso, el jefe de un
 *   departamento del docente y el admin (AC-07). Nombrar jefes ya no es de esta Policy: la ficha usa
 *   RoleAssignmentPolicy de Core (3.P8).
 *
 * Se resuelve con los permisos de FacultyPermission, que el provider concede por puesto:
 * - Sobre un docente (`view`, `update`, `managePositions`, `manageAvailability`, `manageExternalLoads` y la parte del
 *   docente de `manageQualification`): `faculty.teacher.view_all`, que cubre a todos, también sin adscripción (la
 *   Subdirección), o el permiso por unidad de la acción (`faculty.teacher.view`, `update`, `manage_positions`,
 *   `manage_availability`, `manage_external_loads`, `faculty.teacher_qualification.manage`) sobre los departamentos
 *   de sus adscripciones sin borrar.
 * - Sobre un departamento (`create` con destino, `removeAdscription`, `setPrimaryAdscription` y la materia de
 *   `manageQualification`): solo el permiso por unidad (`faculty.teacher.create`, `faculty.teacher_department.remove`,
 *   `faculty.teacher_department.set_primary`, `faculty.teacher_qualification.manage`) sobre ese departamento.
 *   `view_all` no lo abre: la Subdirección cubre los de su subárbol, Subdirección Académica, como antes.
 * - Sin dimensiones: `faculty.teacher.deactivate` y `faculty.teacher.adscribe` (la Subdirección), y
 *   `faculty.teacher_user.provision` y `faculty.teacher_user.reset_password`, que
 *   no se conceden a nadie: solo el admin, que tiene todo.
 * «No sobre sí mismo» es una condición del recurso y se queda aquí.
 *
 * No hay `delete`: un docente se da de baja. TeacherQuery lista con la misma regla, en SQL; si cambia aquí, cambia
 * allá.
 */
final class TeacherPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(FacultyPermission::TeacherView);
    }

    public function view(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->actor($user), $teacher, FacultyPermission::TeacherView);
    }

    /**
     * Sus datos: nombre, correo, grado y fecha de ingreso.
     */
    public function update(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->actor($user), $teacher, FacultyPermission::TeacherUpdate);
    }

    /**
     * Darlo de baja o reactivarlo: desactiva su usuario y termina sus jefaturas, en todo el Tec.
     */
    public function deactivate(User $user, Teacher $teacher): bool
    {
        return $this->actor($user)->allows(FacultyPermission::TeacherDeactivate) && ! $this->isSelf($user, $teacher->user_id);
    }

    public function managePositions(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->actor($user), $teacher, FacultyPermission::TeacherManagePositions);
    }

    public function manageAvailability(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->actor($user), $teacher, FacultyPermission::TeacherManageAvailability);
    }

    /**
     * Sus horas fuera del ITCJ (`teacher_external_loads`).
     */
    public function manageExternalLoads(User $user, Teacher $teacher): bool
    {
        return $this->coversTeacher($this->actor($user), $teacher, FacultyPermission::TeacherManageExternalLoads);
    }

    /**
     * Su perfil en una materia: cubre al docente y la materia es de un departamento que cubre.
     */
    public function manageQualification(User $user, Teacher $teacher, Subject $subject): bool
    {
        $scope = $this->actor($user);

        return $this->coversTeacher($scope, $teacher, FacultyPermission::TeacherQualificationManage)
            && $scope->allows(FacultyPermission::TeacherQualificationManage, Target::of(unit: $subject->department_id));
    }

    /**
     * «Quitar de mi departamento», en Docentes y en la validación del catálogo de docentes («Dar de baja de <depto>»):
     * quitar la última adscripción da de baja al docente (CV8, confirmado el 12 de noviembre de 2026;
     * DischargeTeacherFromDepartment). A un docente cuyo usuario ocupa un puesto vigente o por empezar (un jefe, un
     * coordinador, la Subdirección…) solo lo quitan quienes dan de baja docentes, el admin y la Subdirección (CV-S8,
     * confirmado el 12 de noviembre de 2026 para la baja desde Docentes). Gate busca la policy por el modelo, y
     * TeacherDepartment no tiene una propia: se pregunta con `can('removeAdscription', [Teacher::class, $adscription])`.
     */
    public function removeAdscription(User $user, TeacherDepartment $adscription): bool
    {
        $teacherUserId = $adscription->relationLoaded('teacher')
            ? $adscription->teacher?->user_id
            : Teacher::withTrashed()->whereKey($adscription->teacher_id)->value('user_id');

        return $this->actor($user)->allows(FacultyPermission::TeacherDepartmentRemove, Target::of(unit: $adscription->department_id))
            && ! $this->isSelf($user, $teacherUserId)
            && ! $this->staffOnlyForDeactivators($user, $teacherUserId);
    }

    /**
     * Agregar a un departamento a un docente que ya existe, activo o dado de baja (que se reactiva), al validar el
     * catálogo de docentes (CV-S9): quien da de alta docentes en ese departamento (`create` con destino). A uno dado de
     * baja cuyo usuario ocupa un puesto solo lo reactivan quienes dan de baja docentes, el admin y la Subdirección
     * (confirmado el 12 de noviembre de 2026). Fuera de la validación, adscribir a uno que ya existe sigue siendo de
     * `adscribe` (S9). Se pregunta con `can('adscribeToDepartment', [Teacher::class, $department, $teacher])`; sin
     * docente, si puede agregar a alguno.
     */
    public function adscribeToDepartment(User $user, Department $department, ?Teacher $teacher = null): bool
    {
        if (! $this->create($user, $department)) {
            return false;
        }

        return $teacher === null || $teacher->is_active || ! $this->staffOnlyForDeactivators($user, $teacher->user_id);
    }

    /**
     * «Hacer principal»: la adscripción es de un departamento que cubre (supuesto por confirmar). Se pregunta con
     * `can('setPrimaryAdscription', [Teacher::class, $adscription])`.
     */
    public function setPrimaryAdscription(User $user, TeacherDepartment $adscription): bool
    {
        return $this->actor($user)->allows(FacultyPermission::TeacherDepartmentSetPrimary, Target::of(unit: $adscription->department_id));
    }

    /**
     * Sin destino, si da de alta docentes en algún departamento; con el departamento de la adscripción nueva, si lo
     * cubre. Un id vacío (llega del navegador) no es ningún departamento: solo el admin.
     */
    public function create(User $user, Department|string|null $department = null): bool
    {
        $scope = $this->actor($user);

        if ($department === null) {
            return $scope->allowsAny(FacultyPermission::TeacherCreate);
        }

        $departmentId = $department instanceof Department ? $department->getKey() : $department;

        return $scope->allows(FacultyPermission::TeacherCreate, Target::of(unit: $departmentId === '' ? null : $departmentId));
    }

    /**
     * Adscribir a un departamento a un docente que ya existe (S9, supuesto por confirmar): solo el admin y la
     * Subdirección, que ven a todos los docentes; un jefe no puede ni saber de quién es un número que no cubre.
     */
    public function adscribe(User $user): bool
    {
        return $this->actor($user)->allows(FacultyPermission::TeacherAdscribe);
    }

    /**
     * «Dar de alta usuarios»: sincronizar el acceso de todos los docentes (ProvisionTeacherUsersJob).
     */
    public function provisionUsers(User $user): bool
    {
        return $this->actor($user)->allows(FacultyPermission::TeacherUserProvision);
    }

    public function resetPassword(User $user, Teacher $teacher): bool
    {
        return $this->actor($user)->allows(FacultyPermission::TeacherUserResetPassword);
    }

    /**
     * «Reenviar invitación» (AC-07): el jefe de un departamento del docente (`faculty.teacher_user.invite` sobre la
     * unidad) y el admin (supuesto por confirmar). `view_all` no lo abre: ni la Subdirección ni el jefe de proyecto
     * docente la reenvían.
     */
    public function inviteAccess(User $user, Teacher $teacher): bool
    {
        return $this->actor($user)->allows(FacultyPermission::TeacherUserInvite, Target::of(unit: $this->adscriptionsOf($teacher)->pluck('department_id')->unique()->values()->all()));
    }

    /**
     * Cambiar el correo institucional (AC-06): como la invitación va a ese correo, solo quien la puede reenviar
     * (`inviteAccess`). Si el usuario del docente tiene otro puesto vigente o por empezar (un jefe, la Subdirección…),
     * solo el admin (`faculty.teacher_user.update_staff_email`, que no se concede a nadie), aunque a él no se le invite:
     * así nadie lleva a su buzón el acceso de un jefe (supuestos por confirmar).
     */
    public function updateInstitutionalEmail(User $user, Teacher $teacher): bool
    {
        if (! $this->update($user, $teacher) || ! $this->inviteAccess($user, $teacher)) {
            return false;
        }

        return $teacher->user_id === null
            || ! app(RoleAssignmentRules::class)->holdsStaffRole($teacher->user_id)
            || $this->actor($user)->allows(FacultyPermission::TeacherStaffEmailUpdate);
    }

    /**
     * Si cubre al docente para `$permission`: con `faculty.teacher.view_all`, a todos, también a los que no tienen
     * departamento; si no, con `$permission` sobre los departamentos de sus adscripciones sin borrar.
     */
    private function coversTeacher(Actor $scope, Teacher $teacher, FacultyPermission $permission): bool
    {
        return $scope->allows(FacultyPermission::TeacherViewAll)
            || $scope->allows($permission, Target::of(unit: $this->adscriptionsOf($teacher)->pluck('department_id')->unique()->values()->all()));
    }

    /**
     * Si el usuario del docente ocupa un puesto vigente o por empezar y quien actúa no da de baja docentes: entonces la
     * baja, la última adscripción y la reactivación son solo del admin y la Subdirección (CV-S8).
     */
    private function staffOnlyForDeactivators(User $user, ?string $teacherUserId): bool
    {
        return $teacherUserId !== null
            && ! $this->actor($user)->allows(FacultyPermission::TeacherDeactivate)
            && app(RoleAssignmentRules::class)->holdsStaffRole($teacherUserId);
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
