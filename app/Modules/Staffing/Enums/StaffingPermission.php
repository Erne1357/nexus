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
        return match ($this) {
            self::ChangeRequestRequest, self::ChangeRequestApplySchedule => [Dimension::Campus, Dimension::Career],
            default => [Dimension::Campus, Dimension::Unit],
        };
    }

    /**
     * Pedir un cambio como docente es sobre lo propio: el dueño es el usuario del docente asignado al grupo.
     */
    public function isPersonal(): bool
    {
        return $this === self::ChangeRequestRequestOwn;
    }

    /**
     * Lo académico (AZ-02, AZ-S16): a los departamentos académicos y a la Subdirección Académica.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return $this->isPersonal() ? null : UnitApplicability::academic();
    }
}
