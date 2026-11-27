<?php

namespace App\Modules\Academic\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\ReadsByAction;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Academic: catálogos de departamentos, carreras, materias y planes de estudio. La clave es
 * `academic.<recurso>.<accion>`. Todos son solo del admin, salvo `academic.placement.validate`: validar las materias
 * de la migración del SII que esperan semestre (D19), y `academic.subject.set_space`: cambiar el tipo de espacio que pide una
 * materia de su departamento (SEL-17) y si sus horas cuentan en la carga del docente (PL-02; el jefe y el admin). Se concede al jefe de departamento, declara la unidad y aplica
 * solo a Servicios Escolares (AZ-02, AZ-S16): solo lo ejerce el jefe nombrado sobre esa unidad, y StudyPlanPolicy lo pregunta
 * sobre ella.
 */
enum AcademicPermission: string implements Permission
{
    use ReadsByAction;

    case DepartmentView = 'academic.department.view';
    case DepartmentCreate = 'academic.department.create';
    case DepartmentUpdate = 'academic.department.update';
    case DepartmentDelete = 'academic.department.delete';
    case CareerView = 'academic.career.view';
    case CareerCreate = 'academic.career.create';
    case CareerUpdate = 'academic.career.update';
    case CareerDelete = 'academic.career.delete';
    case SubjectView = 'academic.subject.view';
    case SubjectCreate = 'academic.subject.create';
    case SubjectUpdate = 'academic.subject.update';
    case SubjectSpaceSet = 'academic.subject.set_space';
    case StudyPlanView = 'academic.study_plan.view';
    case StudyPlanCreate = 'academic.study_plan.create';
    case StudyPlanUpdate = 'academic.study_plan.update';
    case StudyPlanEditGrid = 'academic.study_plan.edit_grid';
    case PlacementValidate = 'academic.placement.validate';

    public function label(): string
    {
        return match ($this) {
            self::DepartmentView => 'Ver departamentos',
            self::DepartmentCreate => 'Dar de alta departamentos',
            self::DepartmentUpdate => 'Editar departamentos',
            self::DepartmentDelete => 'Borrar departamentos',
            self::CareerView => 'Ver carreras',
            self::CareerCreate => 'Dar de alta carreras',
            self::CareerUpdate => 'Editar carreras',
            self::CareerDelete => 'Borrar carreras',
            self::SubjectView => 'Ver materias',
            self::SubjectCreate => 'Dar de alta materias',
            self::SubjectUpdate => 'Editar materias',
            self::SubjectSpaceSet => 'Cambiar el tipo de espacio de una materia y si cuenta en la carga del docente',
            self::StudyPlanView => 'Ver planes de estudio',
            self::StudyPlanCreate => 'Dar de alta planes de estudio',
            self::StudyPlanUpdate => 'Editar planes de estudio',
            self::StudyPlanEditGrid => 'Editar la retícula de un plan',
            self::PlacementValidate => 'Asignar semestre a las materias por validar de la migración del SII',
        };
    }

    public function dimensions(): array
    {
        return in_array($this, [self::PlacementValidate, self::SubjectSpaceSet], true) ? [Dimension::Unit] : [];
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * AZ-02, AZ-S16: validar los pendientes de la migración del SII, a Servicios Escolares; lo demás es académico (los
     * departamentos académicos y la Subdirección Académica).
     */
    public function appliesTo(): ?UnitApplicability
    {
        return $this === self::PlacementValidate
            ? UnitApplicability::of(codes: [OrgTree::SCHOOL_SERVICES])
            : UnitApplicability::academic();
    }
}
