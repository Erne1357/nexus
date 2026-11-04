<?php

namespace Tests\Fixtures\Modules\DemoCatalog\Enums;

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;

/**
 * Permisos del módulo de ejemplo DemoCatalog, solo para pruebas: la clave es `demo_catalog.<recurso>.<accion>`.
 */
enum DemoCatalogPermission: string implements Permission
{
    case ItemList = 'demo_catalog.item.list';
    case ItemView = 'demo_catalog.item.view';
    case ItemUpdate = 'demo_catalog.item.update';
    case GreetingView = 'demo_catalog.greeting.view';

    public function label(): string
    {
        return match ($this) {
            self::ItemList => 'Listar los elementos',
            self::ItemView => 'Ver un elemento',
            self::ItemUpdate => 'Editar un elemento',
            self::GreetingView => 'Ver el saludo',
        };
    }

    public function dimensions(): array
    {
        return match ($this) {
            self::ItemList, self::GreetingView => [],
            self::ItemView => [Dimension::Campus],
            self::ItemUpdate => [Dimension::Campus, Dimension::Unit, Dimension::Career],
        };
    }

    public function isPersonal(): bool
    {
        return false;
    }
}
