<?php

namespace App\Modules\Core\Queries;

/**
 * Sin un módulo que adscriba personas a las unidades, nadie está adscrito: el Organigrama cuenta solo los
 * nombramientos.
 */
final class NoUnitAdscriptions implements UnitAdscriptions
{
    public function userIdsByUnit(): array
    {
        return [];
    }
}
