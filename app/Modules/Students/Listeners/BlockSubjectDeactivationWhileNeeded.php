<?php

namespace App\Modules\Students\Listeners;

use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Students\Queries\SubjectDemandQuery;

/**
 * Veta desactivar una materia que todavía necesitan alumnos (reglas-de-negocio.md, "Elegibilidad y
 * demanda"): una materia de la retícula cuenta aunque esté inactiva, porque el plan se ata al alumno.
 * Cuenta con SubjectDemandQuery, desde el kárdex del momento, y dice cuántos son y de qué planes.
 * Corre en la misma petición, dentro de la transacción de UpdateSubject.
 */
final class BlockSubjectDeactivationWhileNeeded
{
    public function __construct(private readonly SubjectDemandQuery $demand) {}

    public function handle(SubjectDeactivating $event): void
    {
        $needing = $this->demand->needing($event->subject->id);

        if ($needing['total'] > 0) {
            $event->block("La necesitan {$needing['total']} alumnos (activos o en baja temporal) de los planes ".implode(', ', $needing['plans']).'.');
        }
    }
}
