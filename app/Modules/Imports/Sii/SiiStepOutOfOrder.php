<?php

namespace App\Modules\Imports\Sii;

use RuntimeException;

/**
 * Un paso necesita un mapa que arma un paso anterior y ese paso no corrió en esta corrida (por ejemplo, con
 * `--only materias`). El mensaje solo nombra pasos y mapas, así que la corrida lo muestra tal cual.
 */
final class SiiStepOutOfOrder extends RuntimeException
{
    /**
     * @param  list<string>  $earlier  pasos que deben correr antes
     */
    public function __construct(public readonly string $step, public readonly string $map, public readonly array $earlier)
    {
        parent::__construct("el paso {$step} necesita que corran antes ".implode(' y ', $earlier)." (falta el mapa {$map})");
    }
}
