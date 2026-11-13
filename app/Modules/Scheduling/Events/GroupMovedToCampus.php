<?php

namespace App\Modules\Scheduling\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un grupo cambió de campus (MoveGroupToCampus; M8, MS9). Sale DENTRO de la transacción del cambio, a propósito: los
 * módulos de la derecha que guardan una copia del campus del grupo (Staffing: `staffing_rankings`, `change_requests`)
 * la cambian en la misma transacción con un listener síncrono, así ninguna copia queda con el campus de antes si algo
 * falla. Lo que va a la cola (recalcular el ranking, los choques) lo encola cada quien después del commit; el aviso de
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
