<?php

namespace App\Modules\Staffing\Listeners;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Jobs\RefreshTeacherLoads;

/**
 * Encola el recálculo de `teacher_term_loads` (RefreshTeacherLoads; tarea 3.12) del periodo que cambió: los grupos con
 * docente de Scheduling (asignar, cancelar, nacer) y, de Faculty, las plazas, las horas externas (de su periodo), los
 * datos y las adscripciones de un docente, que valen para cada periodo con una carga en `proposed`, `staffing` o
 * `adjusting`. Todos salen después del commit, así que el job lee lo guardado.
 */
final class QueueTeacherLoadRefresh
{
    public function handle(object $event): void
    {
        foreach ($this->termIds($event) as $termId) {
            RefreshTeacherLoads::queueFor($termId);
        }
    }

    /**
     * @return list<string>
     */
    private function termIds(object $event): array
    {
        return match (true) {
            $event instanceof GroupTeacherChanged => [$event->group->term_id],
            $event instanceof GroupCancelled => $event->group->teacher_id === null ? [] : [$event->group->term_id],
            $event instanceof GroupsCreated => Group::query()->whereIn('id', $event->groupIds)->whereNotNull('teacher_id')->exists() ? [$event->termId] : [],
            $event instanceof ExternalLoadChanged => [$event->load->term_id],
            $event instanceof PositionChanged, $event instanceof TeacherUpdated, $event instanceof AdscriptionChanged => $this->openTerms(),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function openTerms(): array
    {
        return AcademicLoad::query()
            ->whereIn('status', [LoadStatus::Proposed->value, LoadStatus::Staffing->value, LoadStatus::Adjusting->value])
            ->distinct()
            ->pluck('term_id')
            ->all();
    }
}
