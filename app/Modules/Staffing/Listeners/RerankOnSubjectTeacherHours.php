<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\Academic\Events\SubjectUpdated;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\QueueRankings;

/**
 * Cambió si las horas de una materia cuentan en la carga del docente (PL-02, tarea 1.73): cambia la marca de HC-08 en
 * el ranking de sus grupos y las horas que les quedan a quienes la dan. Encola los alcances de cada grupo no cancelado
 * de la materia en los periodos con carga abierta, con su docente (QueueRankings::forGroup). Otro cambio de la materia
 * no toca el ranking.
 */
final class RerankOnSubjectTeacherHours
{
    public function __construct(private readonly QueueRankings $queue) {}

    public function handle(SubjectUpdated $event): void
    {
        if (! in_array('counts_teacher_hours', $event->changed, true)) {
            return;
        }

        $terms = AcademicLoad::query()
            ->whereIn('status', [LoadStatus::Proposed->value, LoadStatus::Staffing->value, LoadStatus::Adjusting->value, LoadStatus::Reanalysis->value])
            ->distinct()->pluck('term_id')->all();

        Group::query()->where('subject_id', $event->subject->getKey())->whereIn('term_id', $terms)
            ->where('status', '<>', GroupStatus::Cancelled->value)
            ->get(['id', 'term_id', 'modality_id', 'department_id', 'teacher_id'])
            ->each(fn (Group $group) => $this->queue->forGroup($group, [$group->teacher_id]));
    }
}
