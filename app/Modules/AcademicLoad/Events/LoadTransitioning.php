<?php

namespace App\Modules\AcademicLoad\Events;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Events\Concerns\CanBeVetoed;

/**
 * Una carga va a cambiar de estado (TransitionLoad). Evento con veto (docs/arquitectura/arquitectura.md,
 * "Eventos con veto"): se despacha dentro de la transacción y antes de escribir, para que los módulos de la
 * derecha (Scheduling, Staffing) agreguen sus guardas con `block('motivo')`.
 */
final class LoadTransitioning
{
    use CanBeVetoed;

    public function __construct(
        public AcademicLoad $load,
        public LoadStatus $from,
        public LoadStatus $to,
    ) {}
}
