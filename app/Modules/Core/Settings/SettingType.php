<?php

namespace App\Modules\Core\Settings;

/**
 * La forma del valor de un parámetro (CF-S7): un entero, un decimal, un sí o no, o una tabla de valores con nombre
 * (los pesos del ranking, los tamaños de grupo), que se guarda como un objeto json. En una tabla de opciones
 * (`ChoiceTable`), cada campo toma una de las opciones de la definición (el motor de horarios de cada modalidad).
 */
enum SettingType: string
{
    case Integer = 'int';
    case Decimal = 'float';
    case Boolean = 'bool';
    case IntegerTable = 'int-table';
    case DecimalTable = 'float-table';
    case ChoiceTable = 'choice-table';

    public function isTable(): bool
    {
        return $this === self::IntegerTable || $this === self::DecimalTable || $this === self::ChoiceTable;
    }
}
