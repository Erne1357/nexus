<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Staffing (docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos"): ver el ranking de un
 * grupo lo hacen los jefes, el jefe de proyecto docente y la Subdirección; asignar el docente, los jefes. La clave es
 * `staffing.<recurso>.<accion>`. El objetivo es un grupo: su campus y su departamento (unidad).
 */
enum StaffingPermission: string implements Permission
{
    case RankingView = 'staffing.ranking.view';
    case TeacherAssign = 'staffing.teacher.assign';
    case TeacherLoadView = 'staffing.teacher_load.view';

    public function label(): string
    {
        return match ($this) {
            self::RankingView => 'Ver el ranking de docentes de un grupo',
            self::TeacherAssign => 'Asignar el docente de un grupo',
            self::TeacherLoadView => 'Ver la carga por docente de un departamento',
        };
    }

    public function dimensions(): array
    {
        return [Dimension::Campus, Dimension::Unit];
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
