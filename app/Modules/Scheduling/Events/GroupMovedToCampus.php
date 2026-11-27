<?php

namespace App\Modules\Scheduling\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un grupo cambió de campus (MoveGroupToCampus; M8, MS9). Sale DENTRO de la transacción del cambio, a propósito: un
 * módulo de la derecha que guardara una copia del campus del grupo la cambiaría en la misma transacción con un listener
 * síncrono, así ninguna copia quedaría con el campus de antes si algo falla (Staffing ya no copia el campus: lo lee del
 * grupo). Lo que va a la cola (recalcular el ranking, los choques) lo encola cada quien después del commit; el aviso de
 * siempre, GroupScheduleChanged, sale después del commit.
 */
final class GroupMovedToCampus
{
    use Dispatchable;

    public function __construct(
        public string $groupId,
        public string $termId,
        public string $modalityId,
        public string $fromCampusId,
        public string $toCampusId,
    ) {}
}
