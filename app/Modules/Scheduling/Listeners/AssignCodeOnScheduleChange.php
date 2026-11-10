<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Events\GroupScheduleChanged;

/**
 * Cuando cambian las sesiones de un grupo, su clave sigue la hora de inicio más temprana (AssignGroupCode; tarea 3.3).
 */
final class AssignCodeOnScheduleChange
{
    public function __construct(private readonly AssignGroupCode $assignCode) {}

    public function handle(GroupScheduleChanged $event): void
    {
        if (! $event->timesChanged) {
            return;
        }

        $this->assignCode->handle($event->group);
    }
}
