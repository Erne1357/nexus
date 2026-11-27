<?php

namespace App\Modules\Imports\Templates;

/**
 * Las fechas de las exportaciones del SII de Faculty (`dd/mm/aaaa`) a texto ISO, que es como las guarda la fila
 * normalizada (ver ImportTemplate::normalize()). Lo que no es una fecha real queda tal cual, para que la regla
 * `date_format:Y-m-d` de la plantilla lo reporte como error de esa fila.
 */
trait ParsesSiiDates
{
    protected static function siiDate(mixed $value): mixed
    {
        if (! is_string($value) || preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $parts) !== 1) {
            return $value;
        }

        [, $day, $month, $year] = array_map(intval(...), $parts);

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : $value;
    }
}
