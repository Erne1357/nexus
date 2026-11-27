<?php

namespace App\Modules\Imports\Sii;

use RuntimeException;

/**
 * Un catálogo curado a mano ya no coincide con la exportación del SII (faltan o sobran claves) o trae filas que no
 * cumplen su contrato (invalid()). Las claves no son datos personales, así que el mensaje las nombra y la corrida lo
 * muestra tal cual.
 */
final class SiiCuratedMismatch extends RuntimeException
{
    /**
     * @param  list<string>  $missing  claves del SII sin fila curada; con `$problem`, las filas que lo tienen
     * @param  list<string>  $extra  claves curadas que el SII no trae
     * @param  string  $problem  vacío si faltan o sobran claves; si no, qué tienen mal las filas de `$missing`
     */
    public function __construct(public readonly string $catalog, public readonly array $missing, public readonly array $extra, public readonly string $problem = '')
    {
        parent::__construct($problem === ''
            ? sprintf(
                'El catálogo curado %s no coincide con la exportación del SII: sin fila curada [%s], curadas que el SII no trae [%s].',
                $catalog,
                implode(', ', $missing),
                implode(', ', $extra),
            )
            : sprintf('El catálogo curado %s no es válido: %s [%s].', $catalog, $problem, implode(', ', $missing)));
    }

    /**
     * Filas curadas que existen en el SII pero no cumplen el contrato del catálogo.
     *
     * @param  list<string>  $rows  las filas (o claves) con el problema
     */
    public static function invalid(string $catalog, string $problem, array $rows): self
    {
        return new self($catalog, $rows, [], $problem);
    }
}
