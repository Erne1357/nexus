<?php

namespace App\Modules\Solver\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Solver (tarea 6.2; decisión del usuario del 17 de noviembre de 2026). La clave es
 * `solver.<recurso>.<accion>`. Las corridas de la carga de un departamento declaran modalidad y unidad (el
 * departamento); las de una carrera, modalidad y carrera. Aceptar escribe el horario de la carga, así que solo existe
 * para las de departamento.
 */
enum SolverPermission: string implements Permission
{
    case DepartmentRunView = 'solver.department_run.view';
    case DepartmentRunLaunch = 'solver.department_run.launch';
    case DepartmentRunApply = 'solver.department_run.apply';
    case CareerRunView = 'solver.career_run.view';
    case CareerRunLaunch = 'solver.career_run.launch';
    case SettingUpdate = 'solver.setting.update';

    public function label(): string
    {
        return match ($this) {
            self::DepartmentRunView => 'Ver las corridas del motor de la carga de un departamento',
            self::DepartmentRunLaunch => 'Lanzar el motor sobre la carga de un departamento',
            self::DepartmentRunApply => 'Aceptar el horario que propone el motor para la carga de un departamento',
            self::CareerRunView => 'Ver las corridas del motor de una carrera',
            self::CareerRunLaunch => 'Lanzar el motor sobre los grupos de una carrera para explorar',
            self::SettingUpdate => 'Cambiar los parámetros del motor de horarios',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::DepartmentRunView, self::DepartmentRunLaunch, self::DepartmentRunApply => [Dimension::Modality, Dimension::Unit],
            self::CareerRunView, self::CareerRunLaunch => [Dimension::Modality, Dimension::Career],
            self::SettingUpdate => [],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): los departamentos académicos y la Subdirección Académica. Cambiar los parámetros
     * es global y no depende de la unidad del puesto.
     */
    public function appliesTo(): ?UnitApplicability
    {
        if ($this === self::SettingUpdate) {
            return null;
        }

        return UnitApplicability::academic();
    }
}
