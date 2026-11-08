<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Staffing\Actions\QueueRankings;

/**
 * Cambió un grupo: su horario (GroupScheduleChanged), su estado (GroupCancelled) o su docente (GroupTeacherChanged).
 * Encola el ranking del departamento del grupo y, en el periodo del grupo, los alcances de su docente (el anterior y
 * el nuevo si cambió): sus choques y sus horas cambian en todos los grupos donde es candidato.
 */
final class RerankOnGroupChange
{
    public function __construct(private readonly QueueRankings $queue) {}

    public function handle(GroupScheduleChanged|GroupCancelled|GroupTeacherChanged $event): void
    {
        $teacherIds = $event instanceof GroupTeacherChanged
            ? [$event->oldTeacherId, $event->newTeacherId]
            : [$event->group->teacher_id];

        $this->queue->forGroup($event->group, $teacherIds);
    }
}
