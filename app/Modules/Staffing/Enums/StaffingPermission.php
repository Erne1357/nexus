<?php

namespace App\Modules\Staffing\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Staffing (docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos"): ver el ranking de un
 * grupo lo hacen los jefes, el jefe de proyecto docente y la Subdirección; asignar el docente, los jefes. La clave es
 * `staffing.<recurso>.<accion>`. El objetivo de los de gestión es un grupo: su campus y su departamento (unidad).
 */
enum StaffingPermission: string implements Permission
{
    case RankingView = 'staffing.ranking.view';
    case TeacherAssign = 'staffing.teacher.assign';
    case OfferView = 'staffing.offer.view';
    case MyLoadView = 'staffing.my_load.view';

    public function label(): string
    {
        return match ($this) {
            self::RankingView => 'Ver el ranking de docentes de un grupo',
            self::TeacherAssign => 'Asignar el docente de un grupo',
            self::OfferView => 'Ver la oferta de mis departamentos',
            self::MyLoadView => 'Ver mi carga',
        };
    }

    public function dimensions(): array
    {
        return $this->isPersonal() ? [] : [Dimension::Campus, Dimension::Unit];
    }

    /**
     * «Mi espacio» (ADR-0018): la oferta de mis departamentos y mi carga son del docente sobre lo suyo; no declaran
     * dimensiones.
     */
    public function isPersonal(): bool
    {
        return match ($this) {
            self::OfferView, self::MyLoadView => true,
            default => false,
        };
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return UnitApplicability::academic();
    }
}
