<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\Core\Events\TermOpeningPreviewing;

/**
 * Antes de abrir un periodo en una modalidad dice qué cargas crearía CreateLoadsOnTermOpened (M4), con el mismo cálculo
 * (CreateTermLoads::preview()) y sin escribir: «3 cargas académicas en borrador, una por departamento activo, con los
 * grupos de todos los campus de la modalidad» (M1; MS15, supuesto por confirmar).
 */
final class PreviewLoadsOnTermOpening
{
    public function __construct(private readonly CreateTermLoads $createTermLoads) {}

    public function handle(TermOpeningPreviewing $event): void
    {
        $preview = $this->createTermLoads->preview($event->term, $event->modality);

        $event->add(
            $preview['loads'],
            ($preview['loads'] === 1 ? 'carga académica' : 'cargas académicas').' en borrador, una por departamento activo, con los grupos de todos los campus de la modalidad.',
            $preview['departments'],
        );
    }
}
