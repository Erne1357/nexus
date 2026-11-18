<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\History\SyncPublishedGroupHistory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;

/**
 * Al publicarse una carga (`LoadTransitioned` a `published`, después del commit), lleva sus grupos al historial por
 * materia (tarea 5.6, SyncPublishedGroupHistory). Cancelar un re-análisis (RA-10) no crea versión, así que no hay nada que llevar.
 */
final class RecordGroupHistoryOnPublication
{
    public function __construct(private readonly SyncPublishedGroupHistory $history) {}

    public function handle(LoadTransitioned $event): void
    {
        if ($event->to === LoadStatus::Published && $event->from !== LoadStatus::Reanalysis) {
            $this->history->handle($event->load);
        }
    }
}
