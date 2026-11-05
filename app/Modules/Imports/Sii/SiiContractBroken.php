<?php

namespace App\Modules\Imports\Sii;

use RuntimeException;

/**
 * La exportación del SII ya no cumple el contrato de encabezados: la migración se detiene.
 *
 * `differences` va por archivo (valor de `SiiFile`). Un archivo que no está en la carpeta se reporta
 * ahí mismo con `missing` igual a todos sus encabezados y `extra` vacío.
 */
final class SiiContractBroken extends RuntimeException
{
    /**
     * @param  array<string, array{missing: list<string>, extra: list<string>}>  $differences
     */
    public function __construct(public readonly array $differences)
    {
        $lines = [];
        foreach ($differences as $file => $diff) {
            $lines[] = sprintf(
                '%s: faltan [%s], sobran [%s]',
                $file,
                implode(', ', $diff['missing']),
                implode(', ', $diff['extra']),
            );
        }

        parent::__construct('La exportación del SII no cumple el contrato de encabezados: '.implode('; ', $lines).'.');
    }
}
