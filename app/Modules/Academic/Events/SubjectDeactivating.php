<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Events\Concerns\CanBeVetoed;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se va a desactivar una materia (UpdateSubject, `is_active` de true a false). Evento con veto: se
 * despacha dentro de la transacción, antes de guardar, y un módulo de la derecha lo bloquea con
 * block() si la materia todavía le hace falta. Students lo bloquea si hay alumnos que la necesitan
 * (reglas-de-negocio.md, "Elegibilidad y demanda"): una materia de la retícula cuenta aunque esté
 * inactiva, porque el plan se ata al alumno. Si alguien bloquea, UpdateSubject rechaza con sus
 * motivos en `is_active`.
 */
final class SubjectDeactivating
{
    use CanBeVetoed, Dispatchable;

    public function __construct(public Subject $subject) {}
}
