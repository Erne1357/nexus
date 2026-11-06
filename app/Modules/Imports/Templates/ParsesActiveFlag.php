<?php

namespace App\Modules\Imports\Templates;

/**
 * El «activo» de una exportación del SII como booleano: vacío es null (el alta nace activa) y lo que no entiende
 * se queda como texto, para que `rules()` (`boolean`) lo rechace.
 */
trait ParsesActiveFlag
{
    protected static function activeFlag(mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }

        return match (ImportTemplate::slug((string) $value)) {
            'si', 's', 'activo', '1', 'true', 'verdadero' => true,
            'no', 'n', 'inactivo', '0', 'false', 'falso', 'baja' => false,
            default => $value,
        };
    }
}
