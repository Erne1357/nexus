<?php

namespace App\Modules\Scheduling\Rules;

/**
 * La clave de un grupo con la nomenclatura del SII (reglas-de-negocio.md, "Grupos y horarios"; confirmado el 7 de
 * noviembre de 2026): la letra es la hora de inicio, A = 7:00, B = 8:00 … O = 21:00. Una hora antes de las 7:00 toma la
 * A y de las 21:00 en adelante la O (supuesto por confirmar). Si otro grupo de la misma materia y periodo ya tiene la
 * letra, se le agrega el siguiente número libre (D2, D3…). Sin sesiones el grupo lleva un número provisional
 * consecutivo (1, 2…), que ninguna clave del SII usa (supuesto por confirmar).
 *
 * Es puro: recibe las claves que ya tienen los otros grupos no cancelados de la materia y el periodo.
 */
final class GroupCode
{
    private const int FIRST_HOUR = 7;

    private const int LETTERS = 15;

    /**
     * La letra de una hora de inicio, en minutos desde la medianoche.
     */
    public static function letterFor(int $startMinutes): string
    {
        $index = max(0, min(self::LETTERS - 1, intdiv($startMinutes, 60) - self::FIRST_HOUR));

        return chr(ord('A') + $index);
    }

    /**
     * El primer número consecutivo que no usa otra clave.
     *
     * @param  list<string>  $taken
     */
    public static function provisional(array $taken): string
    {
        $number = 1;

        while (in_array((string) $number, $taken, true)) {
            $number++;
        }

        return (string) $number;
    }

    /**
     * La clave que corresponde a un grupo: la letra de su hora de inicio más temprana con el número que haga falta
     * para no repetirse, o la provisional si no tiene sesiones (`null`).
     *
     * @param  list<string>  $taken
     */
    public static function forStart(?int $earliestStartMinutes, array $taken): string
    {
        if ($earliestStartMinutes === null) {
            return self::provisional($taken);
        }

        $letter = self::letterFor($earliestStartMinutes);

        if (! in_array($letter, $taken, true)) {
            return $letter;
        }

        $number = 2;

        while (in_array($letter.$number, $taken, true)) {
            $number++;
        }

        return $letter.$number;
    }
}
