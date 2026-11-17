<?php

namespace App\Modules\AcademicLoad\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cambió algo de los paquetes o del nuevo ingreso de una propuesta (composición, cuentas por campus o nuevo ingreso).
 * Sale después del commit, por si la pantalla quiere refrescarse. Lleva ids, no modelos: un paquete borrado ya no existe.
 */
final class ProposalPackagesChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $proposalId, public ?string $packageId = null) {}
}
