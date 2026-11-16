<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\History\SyncPublishedGroupHistory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;

/**
 * Al publicarse una carga (`LoadTransitioned` a `published`, después del commit), lleva sus grupos al historial por
 * materia (tarea 5.6, SyncPublishedGroupHistory).
 */
final class RecordGroupHistoryOnPublication
{
    public function __construct(private readonly SyncPublishedGroupHistory $history) {}

    public function handle(LoadTransitioned $event): void
    {
        if ($event->to === LoadStatus::Published) {
            $this->history->handle($event->load);
        }
    }
}
