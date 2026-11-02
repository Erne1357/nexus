<?php

namespace App\Modules\CatalogValidation\Enums;

/**
 * Tipo de una observación del jefe sobre un registro de una revisión (docs/dominio/modelo-de-datos.md,
 * `catalog_review_flags`). Las observaciones no bloquean validar (confirmado el 2 de noviembre de 2026).
 */
enum ReviewFlag: string
{
    case Inactive = 'inactive';
    case WrongCapacity = 'wrong_capacity';
    case Duplicate = 'duplicate';
    case MissingData = 'missing_data';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Inactive => 'Debe desactivarse',
            self::WrongCapacity => 'Capacidad incorrecta',
            self::Duplicate => 'Duplicado',
            self::MissingData => 'Faltan datos',
            self::Other => 'Otra',
        };
    }

    /**
     * Si se puede poner en un registro del catálogo: la capacidad solo la tienen los salones (supuesto por
     * confirmar).
     */
    public function appliesTo(Catalog $catalog): bool
    {
        return $this !== self::WrongCapacity || $catalog === Catalog::Rooms;
    }
}
