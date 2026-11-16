<?php

namespace App\Modules\Demand\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se recalculó la proyección de un periodo en una modalidad (ComputeDemandForecasts, tarea 5.4): de un campus, o de
 * todos si `$campusId` es null, y de las carreras de `$careerIds`. Lo escucha AcademicLoad para poner al día lo sugerido
 * en las propuestas en borrador: las filas que el coordinador no tocó siguen a la proyección nueva y las que corrigió se
 * conservan con el aviso «ahora sugiere N» (confirmado el 16 de noviembre de 2026; tarea 5.5). Sale después del commit:
 * nadie lee una proyección que se revirtió. Lleva ids y no modelos, porque sus listeners pueden ir a la cola.
 */
final class DemandForecastsComputed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $careerIds
     */
    public function __construct(
        public string $termId,
        public string $modalityId,
        public ?string $campusId,
        public array $careerIds,
    ) {}
}
