<?php

namespace App\Modules\Academic\Events;

use App\Modules\Academic\Models\Specialty;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambió una especialidad de un plan: se dio de alta (CreateSpecialty), cambió su nombre o su estado
 * (UpdateSpecialty) o se quitó (RemoveSpecialty; la que llega está borrada). Sale después del commit. No
 * cambia el avance de nadie: el avance sale de las materias de la retícula y de su especialidad, que estas
 * Actions no tocan.
 */
final class SpecialtyChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Specialty $specialty) {}
}
