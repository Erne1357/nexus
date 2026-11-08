<?php

namespace App\Modules\Scheduling\Listeners;

use App\Modules\Facilities\Events\RoomBlockAdded;
use App\Modules\Facilities\Events\RoomBlockRemoved;
use App\Modules\Facilities\Events\RoomUpdated;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Queries\ConflictRecomputeTargetsQuery;

/**
 * Encola el recálculo de choques (RecomputeScheduleConflicts) de cada carga que cambió (tarea 3.2: «el job recalcula
 * al guardar sesión, docente o salón»): los eventos de Scheduling, los de salones de Facilities y los de docentes de
 * Faculty, que están a la izquierda en el mapa. Todos salen después del commit, así que el job lee lo guardado.
 */
final class QueueConflictRecompute
{
    public function __construct(private readonly ConflictRecomputeTargetsQuery $targets) {}

    public function handle(object $event): void
    {
        $targets = match (true) {
            $event instanceof GroupsCreated => [[$event->termId, $event->campusId]],
            $event instanceof GroupScheduleChanged, $event instanceof GroupCancelled => $this->targets->forGroup($event->group, [$event->group->teacher_id]),
            $event instanceof GroupTeacherChanged => $this->targets->forGroup($event->group, [$event->oldTeacherId, $event->newTeacherId]),
            $event instanceof RoomUpdated => $this->targets->forRoom($event->room),
            $event instanceof RoomBlockAdded, $event instanceof RoomBlockRemoved => $this->targets->forRoom($event->block->room, $event->block->term_id),
            $event instanceof AvailabilityChanged => $this->targets->forTeacher($event->teacher->id, $event->term->id),
            $event instanceof ExternalLoadChanged => $this->targets->forTeacher($event->load->teacher_id, $event->load->term_id),
            $event instanceof PositionChanged => $this->targets->forTeacher($event->position->teacher_id),
            default => [],
        };

        foreach ($targets as [$termId, $campusId]) {
            RecomputeScheduleConflicts::dispatch($termId, $campusId);
        }
    }
}
