<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La carga se está cerrando (CloseLoad). Se despacha dentro de la transacción del cierre, después de que TransitionLoad
 * la dejó en `closed` y antes del commit, para que los módulos de la derecha congelen lo suyo en la misma operación:
 * Scheduling confirma los grupos activos del departamento (P1, confirmado el 10 de noviembre de 2026). Si un listener
 * falla, el cierre se revierte completo. No es un veto: las guardas del cierre van en LoadTransitioning. Lleva a quien
 * cierra, porque un comando o la demo cierran sin sesión.
 */
final class LoadClosing
{
    use Dispatchable;

    public function __construct(public AcademicLoad $load, public ?User $actor = null) {}
}
