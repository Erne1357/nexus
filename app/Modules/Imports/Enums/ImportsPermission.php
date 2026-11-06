<?php

namespace App\Modules\Imports\Enums;

use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Imports: lotes de importación. La clave es `imports.<recurso>.<accion>`. Todos son solo del admin: ningún rol los recibe por concesión.
 */
enum ImportsPermission: string implements Permission
{
    case BatchView = 'imports.batch.view';
    case BatchCreate = 'imports.batch.create';
    case BatchApply = 'imports.batch.apply';

    public function label(): string
    {
        return match ($this) {
            self::BatchView => 'Ver lotes de importación',
            self::BatchCreate => 'Subir lotes de importación',
            self::BatchApply => 'Aplicar lotes de importación',
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
     * A cualquier unidad: ningún permiso del módulo depende de la unidad del puesto (AZ-S16).
     */
    public function appliesTo(): ?UnitApplicability
    {
        return null;
    }
}
