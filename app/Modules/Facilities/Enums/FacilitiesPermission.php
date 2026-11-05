<?php

namespace App\Modules\Facilities\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\UnitApplicability;

/**
 * Permisos del módulo Facilities: edificios y salones. La clave es `facilities.<recurso>.<accion>`.
 */
enum FacilitiesPermission: string implements Permission
{
    case BuildingView = 'facilities.building.view';
    case BuildingCreate = 'facilities.building.create';
    case BuildingUpdate = 'facilities.building.update';
    case RoomView = 'facilities.room.view';
    case RoomCreate = 'facilities.room.create';
    case RoomUpdate = 'facilities.room.update';

    public function label(): string
    {
        return match ($this) {
            self::BuildingView => 'Ver edificios',
            self::BuildingCreate => 'Dar de alta edificios',
            self::BuildingUpdate => 'Editar edificios',
            self::RoomView => 'Ver salones',
            self::RoomCreate => 'Dar de alta salones',
            self::RoomUpdate => 'Editar salones y sus bloqueos',
        };
    }

    public function dimensions(): array
    {
        return [Dimension::Campus];
    }

    public function isPersonal(): bool
    {
        return false;
    }

    /**
     * A cualquier unidad: ningún permiso del módulo depende de la unidad del puesto (D20).
     */
    public function appliesTo(): ?UnitApplicability
    {
        return null;
    }
}
