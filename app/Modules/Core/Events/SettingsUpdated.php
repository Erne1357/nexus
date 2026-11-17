<?php

namespace App\Modules\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cambió el valor de uno o más parámetros (CF-S7). Se despacha una vez por cada «Guardar» o «Volver al valor por
 * omisión», ya confirmada la transacción, y solo con las llaves cuyo valor cambió de verdad; si nada cambió, no se despacha.
 */
final readonly class SettingsUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $keys  llaves de `settings` que cambiaron (`demand.min_group_size`)
     */
    public function __construct(public array $keys) {}
}
