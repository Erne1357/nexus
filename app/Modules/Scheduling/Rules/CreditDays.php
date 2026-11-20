<?php

namespace App\Modules\Scheduling\Rules;

/**
 * Los días de clase de un grupo según sus créditos (regla de datos R-V2-7, reglas-de-negocio.md), para cargar la
 * proyección del departamento, que solo trae la hora de inicio: una hora de clase por crédito a la semana, a la misma
 * hora cada día.
 *
 * Confirmado (verificado con el SII 20253): 5 créditos de lunes a viernes, 4 de lunes a jueves y 1 el viernes.
 * Supuestos por confirmar: con 2 o 3 créditos, los primeros N días hábiles (lunes y martes; lunes a miércoles); con 6,
 * lunes a viernes y la sexta hora el lunes, a la hora siguiente (sesión continua de dos horas). Otros créditos no tienen regla.
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
            $credits === 6 => [1 => 2, 2 => 1, 3 => 1, 4 => 1, 5 => 1],
            default => null,
        };
    }

    /**
     * Si la regla de esos créditos es un supuesto por confirmar (todo menos 1, 4 y 5).
     */
    public static function isAssumed(int $credits): bool
    {
        return ! in_array($credits, [1, 4, 5], true);
    }
}
