<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\Transitions\NotifyReanalysisAvailable;
use App\Modules\Demand\Events\DemandForecastsComputed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Al recalcular la proyección de un periodo en una modalidad, avisa en cola a los jefes cuya carga publicada ya puede
 * re-analizarse (NotifyReanalysisAvailable; RA-01 y RA-02, tarea 8.1). Escucha este evento y no `Imports\BatchApplied`
 * porque el lote sale antes de que el avance de los alumnos y la proyección se recalculen (la cadena es asíncrona): en
 * ese momento la consulta de ReanalysisAvailabilityQuery todavía es falsa. Este evento es cuando se vuelve verdadera.
 */
final class NotifyReanalysisAvailableOnForecastsComputed implements ShouldQueue
{
    public function __construct(private readonly NotifyReanalysisAvailable $notify) {}

    public function handle(DemandForecastsComputed $event): void
    {
        $this->notify->handle($event->termId, $event->modalityId);
    }
}
