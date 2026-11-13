<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\Core\Events\TermOpeningPreviewing;

/**
 * Antes de abrir un periodo en una modalidad dice qué cargas crearía CreateLoadsOnTermOpened (M4), con el mismo cálculo
 * (CreateTermLoads::preview()) y sin escribir: «6 cargas académicas en borrador, una por departamento activo en cada
 * campus de la modalidad». Una modalidad sin campus activos no crea ninguna y lo dice.
 */
final class PreviewLoadsOnTermOpening
{
    public function __construct(private readonly CreateTermLoads $createTermLoads) {}

    public function handle(TermOpeningPreviewing $event): void
    {
        $preview = $this->createTermLoads->preview($event->term, $event->modality);

        if ($preview['campuses'] === 0) {
            $event->add(0, 'cargas académicas: la modalidad no tiene ningún campus activo, así que no se crea ninguna.');

            return;
        }

        $event->add(
            $preview['loads'],
            ($preview['loads'] === 1 ? 'carga académica' : 'cargas académicas').' en borrador, una por departamento activo en cada campus de la modalidad.',
            $preview['departments'],
        );
    }
}
