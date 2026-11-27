<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Events\TeachingHoursChanged;
use App\Modules\Staffing\Actions\QueueRankings;

/**
 * Cambió algo de un docente que entra en el ranking (plaza, horas externas, disponibilidad, perfil, adscripción, sus
 * datos, su acceso o sus horas frente a grupo del CSV de la selección, que cambian la marca de HC-08): encola los alcances del docente (QueueRankings). Las horas externas y la disponibilidad son de
 * un periodo y solo cuentan en él; lo demás, en los periodos con carga abierta.
 */
final class RerankOnFacultyChange
{
    public function __construct(private readonly QueueRankings $queue) {}

    public function handle(PositionChanged|ExternalLoadChanged|AvailabilityChanged|QualificationChanged|AdscriptionChanged|TeacherUpdated|TeacherAccessChanged|TeachingHoursChanged $event): void
    {
        // Las horas frente a grupo del CSV (HC-08, tarea 1.50) son de varios docentes en un periodo.
        if ($event instanceof TeachingHoursChanged) {
            $this->queue->forTeachers($event->teacherIds, $event->termId);

            return;
        }

        [$teacherId, $termId] = match (true) {
            $event instanceof PositionChanged => [$event->position->teacher_id, null],
            $event instanceof ExternalLoadChanged => [$event->load->teacher_id, $event->load->term_id],
            $event instanceof AvailabilityChanged => [$event->teacher->id, $event->term->id],
            $event instanceof QualificationChanged => [$event->qualification->teacher_id, null],
            $event instanceof AdscriptionChanged => [$event->adscription->teacher_id, null],
            default => [$event->teacher->id, null],
        };

        $this->queue->forTeachers([$teacherId], $termId);
    }
}
