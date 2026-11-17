<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\Proposals\SyncProposalIntakes;
use App\Modules\AcademicLoad\Actions\Proposals\SyncProposalSuggestions;
use App\Modules\Demand\Events\DemandForecastsComputed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al recalcular la proyección de Demand, pone al día en cola lo sugerido en las propuestas editables (SyncProposalSuggestions;
 * tarea 5.5): las filas sin tocar siguen a la proyección y las corregidas conservan lo capturado. También el nuevo ingreso y los paquetes sugeridos (SyncProposalIntakes; tarea 5.6b).
 */
final class SyncProposalsOnForecastsComputed implements ShouldQueue
{
    public function __construct(private readonly SyncProposalSuggestions $sync, private readonly SyncProposalIntakes $intakes) {}

    public function handle(DemandForecastsComputed $event): void
    {
        $this->sync->handle($event->termId, $event->modalityId, $event->campusId, $event->careerIds);
        $this->intakes->handle($event->termId, $event->modalityId, $event->campusId, $event->careerIds);
    }
}
