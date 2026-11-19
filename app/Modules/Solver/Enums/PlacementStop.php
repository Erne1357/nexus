<?php

namespace App\Modules\Solver\Enums;

/**
 * Por qué ProposePlacements dejó de buscar acomodos (tareas 8.7 y 8.3b): encontró los que se pidieron, ya no hay otro
 * acomodo completo y sin choques duros nuevos, o se acabó su tiempo de motor.
 */
enum PlacementStop: string
{
    case Complete = 'complete';
    case NoPlacement = 'no_placement';
    case TimeLimit = 'time_limit';

    /**
     * El motivo para la pantalla, según cuántas alternativas encontró; nulo si encontró todas.
     */
    public function message(int $found): ?string
    {
        return match ($this) {
            self::Complete => null,
            self::NoPlacement => $found === 0 ? 'El motor no encontró acomodos sin choques.' : 'El motor no encontró más acomodos sin choques.',
            self::TimeLimit => $found === 0
                ? 'Se acabó el tiempo del motor antes de encontrar un acomodo sin choques.'
                : 'Se acabó el tiempo del motor antes de encontrar más acomodos sin choques.',
        };
    }
}
