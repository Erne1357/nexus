<?php

namespace App\Modules\Imports\Enums;

/**
 * Estado de una fila de un lote (`import_rows`). Al aplicar, las `valid` y las `warning` se escriben y
 * pasan a `applied`; las `error` se saltan (reglas-de-negocio.md, "Reglas de datos").
 */
enum RowStatus: string
{
    case Valid = 'valid';
    case Warning = 'warning';
    case Error = 'error';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Válida',
            self::Warning => 'Con advertencia',
            self::Error => 'Con error',
            self::Applied => 'Aplicada',
        };
    }
}
