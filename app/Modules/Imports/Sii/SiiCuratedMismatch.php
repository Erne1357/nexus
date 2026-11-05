<?php

namespace App\Modules\Imports\Sii;

use RuntimeException;

/**
 * Un catálogo curado a mano ya no coincide con la exportación del SII: faltan o sobran claves. Las claves no son
 * datos personales, así que el mensaje las nombra y la corrida lo muestra tal cual.
 */
final class SiiCuratedMismatch extends RuntimeException
{
    /**
     * @param  list<string>  $missing  claves del SII sin fila curada
     * @param  list<string>  $extra  claves curadas que el SII no trae
     */
    public function __construct(public readonly string $catalog, public readonly array $missing, public readonly array $extra)
    {
        parent::__construct(sprintf(
            'El catálogo curado %s no coincide con la exportación del SII: sin fila curada [%s], curadas que el SII no trae [%s].',
            $catalog,
            implode(', ', $missing),
            implode(', ', $extra),
        ));
    }
}
