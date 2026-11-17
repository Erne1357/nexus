<?php

namespace App\Modules\Demand\Enums;

use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Demand. La clave es `demand.<recurso>.<accion>`. Cambiar los parámetros de la proyección es un
 * permiso global, sin modalidad, campus ni unidad: la proyección y sus tamaños de grupo son de todo el Tec (tarea 5.8).
 */
enum DemandPermission: string implements Permission
{
    case SettingUpdate = 'demand.setting.update';

    public function label(): string
    {
        return match ($this) {
            self::SettingUpdate => 'Cambiar los parámetros de la proyección',
        };
    }

    public function dimensions(): array
    {
        return [];
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * A cualquier unidad: el permiso no depende de la unidad del puesto.
     */
    public function appliesTo(): ?UnitApplicability
    {
        return null;
    }
}
