<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Group;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron las sesiones o los salones de un grupo (las Actions del editor, tarea 3.5): se recalculan los choques del
 * periodo, la clave del grupo (que sigue la hora de inicio, como en el SII) y el ranking de Staffing. Sale después del
 * commit.
 */
final class GroupScheduleChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Group $group) {}
}
