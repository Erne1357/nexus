<?php

namespace App\Modules\Scheduling\Validation;

/**
 * La hora de una sesión como la muestran el editor, la bitácora y los mensajes: `HH:MM`, venga de la base (`time`, que
 * llega como `HH:MM:SS`) o del cliente. Es el formato de Minutes::format().
 */
final class Clock
{
    public static function of(string $time): string
    {
        return Minutes::format(Minutes::of($time));
    }
}
