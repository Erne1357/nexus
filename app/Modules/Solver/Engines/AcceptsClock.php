<?php

namespace App\Modules\Solver\Engines;

use Closure;

/**
 * Un motor que mide su plazo con un reloj que se le puede dar: ProposePlacements le pasa el suyo, así que su plazo y
 * el del motor corren con el mismo reloj (y las pruebas lo vencen a mitad de una vuelta con el motor real).
 */
interface AcceptsClock
{
    /**
     * El mismo motor con otro reloj (segundos de un reloj monótono).
     *
     * @param  Closure(): float  $clock
     */
    public function withClock(Closure $clock): static;
}
