<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Actions\CreateTermLoads;
use App\Modules\Core\Events\TermOpened;

/**
 * Al abrir un periodo en un campus crea en borrador las cargas que falten (CreateTermLoads) y lo reporta:
 * «12 cargas académicas nuevas» o «1 carga académica nueva»; nada si no faltaba ninguna. Corre dentro de
 * la transacción de OpenTerm: si falla, la apertura no queda.
 */
final class CreateLoadsOnTermOpened
{
    public function __construct(private readonly CreateTermLoads $createTermLoads) {}

    public function handle(TermOpened $event): void
    {
        $created = $this->createTermLoads->handle($event->term, $event->campus);

        if ($created > 0) {
            $event->report($created === 1 ? '1 carga académica nueva' : "{$created} cargas académicas nuevas");
        }
    }
}
