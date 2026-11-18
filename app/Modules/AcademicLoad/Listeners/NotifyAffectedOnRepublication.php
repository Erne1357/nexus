<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\Transitions\NotifyRepublication;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al publicarse la versión 2 o posterior de una carga, avisa en cola a los afectados (NotifyRepublication; RA-05,
 * RA-06, tarea 8.4). El evento sale después del commit, así que una publicación que se revierte no avisa; la versión es la
 * que la carga tenía al publicarse, y el diff que se lee es el guardado con ella. Cancelar un re-análisis (`reanalysis` →
 * `published`, RA-10) no crea versión: no avisa.
 */
final class NotifyAffectedOnRepublication implements ShouldQueue
{
    public function __construct(private readonly NotifyRepublication $notifyRepublication) {}

    public function handle(LoadTransitioned $event): void
    {
        if ($event->to === LoadStatus::Published && $event->from !== LoadStatus::Reanalysis && $event->load->current_version > 1) {
            $this->notifyRepublication->handle($event->load, $event->load->current_version);
        }
    }
}
