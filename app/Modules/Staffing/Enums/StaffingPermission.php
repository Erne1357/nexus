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
    case TeacherLoadView = 'staffing.teacher_load.view';
    case ChangeRequestRequestOwn = 'staffing.change_request.request_own';
    case ChangeRequestRequest = 'staffing.change_request.request';
    case ChangeRequestView = 'staffing.change_request.view';
    case ChangeRequestDecide = 'staffing.change_request.decide';
    case ChangeRequestApplySchedule = 'staffing.change_request.apply_schedule';
    case ChangeRequestApplyRoom = 'staffing.change_request.apply_room';

    public function label(): string
    {
        return match ($this) {
            self::RankingView => 'Ver el ranking de docentes de un grupo',
            self::TeacherAssign => 'Asignar el docente de un grupo',
            self::OfferView => 'Ver la oferta de mis departamentos',
            self::MyLoadView => 'Ver mi carga',
            self::TeacherLoadView => 'Ver la carga por docente de un departamento',
            self::ChangeRequestRequestOwn => 'Pedir un cambio en un grupo propio (docente)',
            self::ChangeRequestRequest => 'Pedir un cambio en un grupo de su carrera (coordinador)',
            self::ChangeRequestView => 'Ver las solicitudes de cambio de un departamento',
            self::ChangeRequestDecide => 'Aprobar o rechazar una solicitud de cambio',
            self::ChangeRequestApplySchedule => 'Aplicar un cambio de horario aprobado',
            self::ChangeRequestApplyRoom => 'Aplicar un cambio de salón aprobado',
        };
    }

    public function dimensions(): array
    {
        if ($this->isPersonal()) {
            return [];
        }

        return match ($this) {
            self::ChangeRequestRequest, self::ChangeRequestApplySchedule => [Dimension::Campus, Dimension::Career],
            default => [Dimension::Campus, Dimension::Unit],
        };
    }

    /**
     * «Mi espacio» (ADR-0018): la oferta de mis departamentos, mi carga y pedir un cambio en un grupo propio son del
     * docente sobre lo suyo (el dueño es el usuario del docente); no declaran dimensiones.
     */
    public function isPersonal(): bool
    {
        return match ($this) {
            self::OfferView, self::MyLoadView, self::ChangeRequestRequestOwn => true,
            default => false,
        };
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return $this->isPersonal() ? null : UnitApplicability::academic();
    }
}
