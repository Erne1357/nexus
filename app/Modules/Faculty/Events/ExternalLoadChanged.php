<?php

namespace App\Modules\Faculty\Events;

use App\Modules\Faculty\Models\TeacherExternalLoad;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cambiaron las horas fuera del ITCJ de un docente en un periodo: se agregaron, se cambiaron o se quitaron
 * (AddExternalLoad, UpdateExternalLoad, RemoveExternalLoad). Cambian las horas que se le pueden asignar. Sale después
 * del commit.
 */
final class ExternalLoadChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public TeacherExternalLoad $load) {}
}
