<?php

namespace App\Modules\Core\Settings;

/**
 * La forma del valor de un parámetro (CF-S7): un entero, un decimal, un sí o no, o una tabla de valores con nombre
 * (los pesos del ranking, los tamaños de grupo), que se guarda como un objeto json.
 */
enum SettingType: string
{
    case Integer = 'int';
    case Decimal = 'float';
    case Boolean = 'bool';
    case IntegerTable = 'int-table';
    case DecimalTable = 'float-table';

    public function isTable(): bool
    {
        return $this === self::IntegerTable || $this === self::DecimalTable;
    }
}
