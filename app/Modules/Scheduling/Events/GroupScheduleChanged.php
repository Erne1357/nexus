<?php

namespace App\Modules\Scheduling\Events;

use App\Modules\Scheduling\Models\Group;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron las sesiones o los salones de un grupo (las Actions del editor, tarea 3.5): se recalculan los choques del
 * periodo y, si cambiaron días u horas (`$timesChanged`), la clave del grupo (que sigue la hora de inicio, como en el
 * SII) y el ranking de Staffing. Un cambio solo de salón (asignarlo, quitarlo o aceptar o soltar su préstamo) no cambia
 * ni la clave ni el ranking, y rankear un departamento tarda en la cola (unos 75 s en Campus I con el masivo), así que
 * esos listeners lo saltan. Sale después del commit.
 */
final class GroupScheduleChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Group $group, public bool $timesChanged = true) {}
}
