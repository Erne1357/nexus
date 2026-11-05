<?php

namespace App\Modules\Faculty\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Faculty: docentes, sus adscripciones, su perfil y sus usuarios. La clave es
 * `faculty.<recurso>.<accion>`. Los docentes son de todo el Tec: lo que es de un departamento declara la unidad y
 * nada declara campus. `faculty.teacher.view_all` no declara dimensiones: es la regla confirmada el 30 de octubre de
 * 2026 de que la Subdirección ve y administra a todo docente, también al que no tiene departamento (TeacherPolicy).
 */
enum FacultyPermission: string implements Permission
{
    case TeacherViewAll = 'faculty.teacher.view_all';
    case TeacherView = 'faculty.teacher.view';
    case TeacherCreate = 'faculty.teacher.create';
    case TeacherUpdate = 'faculty.teacher.update';
    case TeacherManagePositions = 'faculty.teacher.manage_positions';
    case TeacherManageAvailability = 'faculty.teacher.manage_availability';
    case TeacherManageExternalLoads = 'faculty.teacher.manage_external_loads';
    case TeacherAdscribe = 'faculty.teacher.adscribe';
    case TeacherDeactivate = 'faculty.teacher.deactivate';
    case TeacherQualificationManage = 'faculty.teacher_qualification.manage';
    case TeacherDepartmentRemove = 'faculty.teacher_department.remove';
    case TeacherDepartmentSetPrimary = 'faculty.teacher_department.set_primary';
    case TeacherUserProvision = 'faculty.teacher_user.provision';
    case TeacherUserResetPassword = 'faculty.teacher_user.reset_password';

    public function label(): string
    {
        return match ($this) {
            self::TeacherViewAll => 'Ver y administrar a todos los docentes, también sin departamento',
            self::TeacherView => 'Ver docentes',
            self::TeacherCreate => 'Dar de alta docentes',
            self::TeacherUpdate => 'Editar los datos de un docente',
            self::TeacherManagePositions => 'Administrar las plazas de un docente',
            self::TeacherManageAvailability => 'Administrar la disponibilidad de un docente',
            self::TeacherManageExternalLoads => 'Administrar las horas fuera del ITCJ de un docente',
            self::TeacherAdscribe => 'Adscribir a un docente que ya existe',
            self::TeacherDeactivate => 'Dar de baja o reactivar a un docente',
            self::TeacherQualificationManage => 'Administrar el perfil de materias de un docente',
            self::TeacherDepartmentRemove => 'Quitar a un docente de un departamento',
            self::TeacherDepartmentSetPrimary => 'Hacer principal una adscripción',
            self::TeacherUserProvision => 'Dar de alta los usuarios de los docentes',
            self::TeacherUserResetPassword => 'Restablecer la contraseña de un docente',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::TeacherView,
            self::TeacherCreate,
            self::TeacherUpdate,
            self::TeacherManagePositions,
            self::TeacherManageAvailability,
            self::TeacherManageExternalLoads,
            self::TeacherQualificationManage,
            self::TeacherDepartmentRemove,
            self::TeacherDepartmentSetPrimary => [Dimension::Unit],
            default => [],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return UnitApplicability::academic();
    }
}
