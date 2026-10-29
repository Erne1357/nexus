<?php

namespace App\Modules\Students\Listeners;

use App\Modules\Academic\Events\SubjectDeactivating;
use App\Modules\Students\Queries\SubjectDemandQuery;

/**
 * Veta desactivar una materia que todavía necesitan alumnos (reglas-de-negocio.md, "Elegibilidad y
 * demanda"): una materia de la retícula cuenta aunque esté inactiva, porque el plan se ata al alumno.
 * Cuenta con SubjectDemandQuery, desde el kárdex del momento, y dice cuántos son y de qué planes:
 * «La necesita 1 alumno (activo o en baja temporal) del plan X.» o «La necesitan 3 alumnos (activos
 * o en baja temporal) de los planes X, Y.»; alumnos y planes van en plural cada uno por su lado.
 * Corre en la misma petición, dentro de la transacción de UpdateSubject.
 */
final class BlockSubjectDeactivationWhileNeeded
{
    public function __construct(private readonly SubjectDemandQuery $demand) {}

    public function handle(SubjectDeactivating $event): void
    {
        ['total' => $total, 'plans' => $plans] = $this->demand->needing($event->subject->id);

        if ($total === 0) {
            return;
        }

        $students = $total === 1
            ? 'La necesita 1 alumno (activo o en baja temporal)'
            : "La necesitan {$total} alumnos (activos o en baja temporal)";

        $event->block($students.(count($plans) === 1 ? ' del plan ' : ' de los planes ').implode(', ', $plans).'.');
    }
}
