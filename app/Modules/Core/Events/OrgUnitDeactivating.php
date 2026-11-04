<?php

namespace App\Modules\Core\Events;

use App\Modules\Core\Events\Concerns\CanBeVetoed;
use App\Modules\Core\Models\OrgUnit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se va a desactivar una unidad del organigrama (SetOrgUnitActive). Evento con veto (docs/arquitectura/arquitectura.md,
 * "Eventos con veto"): se despacha dentro de la transacción, con la unidad bloqueada y antes de escribir, para que un
 * módulo de la derecha (por ejemplo, uno que cuelgue salones de la unidad) la bloquee con block().
 */
final class OrgUnitDeactivating
{
    use CanBeVetoed, Dispatchable;

    public function __construct(public OrgUnit $unit) {}
}
