<?php

namespace App\Modules\Scheduling\Rules;

/**
 * Los días de clase de un grupo según sus créditos (regla de datos R-PRY-1, reglas-de-negocio.md), para cargar la
 * proyección del departamento, que solo trae la hora de inicio: una hora de clase por crédito a la semana, a la misma
 * hora cada día.
 *
 * Confirmado (verificado con el SII 20253): 5 créditos de lunes a viernes, 4 de lunes a jueves y 1 el viernes. Con 6, lunes a
 * viernes y la sexta hora NO se acomoda (confirmado por el usuario el 20 de noviembre de 2026: «depende mucho, hay materias que
 * no tienen esa 6.ª hora, otras la dan el sábado y otras la 2.ª el viernes después de la primera»): queda por acomodar a mano.
 * Supuesto por confirmar: con 2 o 3 créditos, los primeros N días hábiles (lunes y martes; lunes a miércoles). Otros créditos
 * no tienen regla.
 *
 * Es puro: devuelve, por día (1 = lunes), cuántas horas seguidas se dan ese día.
 */
final class CreditDays
{
    /**
     * @return array<int, int>|null día => horas seguidas; nulo si los créditos no tienen regla
     */
    public static function for(int $credits): ?array
    {
        return match (true) {
            $credits === 1 => [5 => 1],
            $credits >= 2 && $credits <= 5 => array_fill_keys(range(1, $credits), 1),
            $credits === 6 => array_fill_keys(range(1, 5), 1),
            default => null,
        };
    }

    /**
     * Si la regla de esos créditos es un supuesto por confirmar (2 y 3).
     */
    public static function isAssumed(int $credits): bool
    {
        return in_array($credits, [2, 3], true);
    }

    /**
     * Las horas a la semana que la regla no acomoda y quedan por acomodar a mano (la sexta de una materia de 6 créditos).
     */
    public static function unplacedHours(int $credits): int
    {
        return $credits === 6 ? 1 : 0;
    }
}
