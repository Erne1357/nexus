<?php

namespace App\Modules\Scheduling\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Scheduling (docs/dominio/reglas-de-negocio.md, "Aprobaciones y permisos"): ver los horarios y
 * el tablero de choques, crear y cancelar grupos, editar sesiones, asignar salón y repartir cupos. La clave es
 * `scheduling.<recurso>.<accion>`. El objetivo es un grupo: su campus, su departamento (unidad) y su carrera dueña.
 * Asignar salón y repartir cupos son del departamento que imparte, así que no declaran carrera.
 */
enum SchedulingPermission: string implements Permission
{
    case ScheduleView = 'scheduling.schedule.view';
    case GroupManage = 'scheduling.group.manage';
    case SessionEdit = 'scheduling.session.edit';
    case RoomAssign = 'scheduling.room.assign';
    case QuotaManage = 'scheduling.quota.manage';

    public function label(): string
    {
        return match ($this) {
            self::ScheduleView => 'Ver los horarios y el tablero de choques',
            self::GroupManage => 'Crear o cancelar grupos y corregir su capacidad',
            self::SessionEdit => 'Acomodar las sesiones de un grupo',
            self::RoomAssign => 'Asignar salón a las sesiones de un grupo',
            self::QuotaManage => 'Repartir los cupos por carrera de un grupo',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::RoomAssign, self::QuotaManage => [Dimension::Campus, Dimension::Unit],
            default => [Dimension::Campus, Dimension::Unit, Dimension::Career],
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
