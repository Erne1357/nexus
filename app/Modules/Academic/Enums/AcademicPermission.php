<?php

namespace App\Modules\Academic\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Academic: catálogos de departamentos, carreras, materias y planes de estudio. La clave es
 * `academic.<recurso>.<accion>`. Todos son solo del admin, salvo `academic.placement.validate`: validar las materias
 * de la migración del SII que esperan semestre (D19). Se concede al jefe de departamento, declara la unidad y aplica
 * solo a Servicios Escolares (D20): solo lo ejerce el jefe nombrado sobre esa unidad, y StudyPlanPolicy lo pregunta
 * sobre ella.
 */
enum AcademicPermission: string implements Permission
{
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
            self::StudyPlanView => 'Ver planes de estudio',
            self::StudyPlanCreate => 'Dar de alta planes de estudio',
            self::StudyPlanUpdate => 'Editar planes de estudio',
            self::StudyPlanEditGrid => 'Editar la retícula de un plan',
            self::PlacementValidate => 'Asignar semestre a las materias por validar de la migración del SII',
        };
    }

    public function dimensions(): array
    {
        return $this === self::PlacementValidate ? [Dimension::Unit] : [];
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * D20: validar los pendientes de la migración del SII, a Servicios Escolares; lo demás es académico (los
     * departamentos académicos y la Subdirección Académica).
     */
    public function appliesTo(): ?UnitApplicability
    {
        return $this === self::PlacementValidate
            ? UnitApplicability::of(codes: [OrgTree::SCHOOL_SERVICES])
            : UnitApplicability::academic();
    }
}
