<?php

namespace App\Modules\Imports\Actions;

use RuntimeException;

/**
 * Al aplicar un lote con "desactivar faltantes" hay más registros que no vienen en el archivo de los que
 * el admin confirmó: el catálogo cambió desde la revisión. ApplyImportBatch no desactiva ninguno
 * (reglas-de-negocio.md, "Reglas de datos", confirmado el 29 de octubre de 2026) y el lote queda fallido
 * con este motivo.
 */
final class MissingRecordsChanged extends RuntimeException
{
    public static function between(int $confirmed, int $found): self
    {
        $confirmedText = $confirmed === 1 ? '1 registro que no viene' : "{$confirmed} registros que no vienen";

        return new self("se confirmó desactivar {$confirmedText} en el archivo, pero al aplicar eran {$found} porque el catálogo cambió. No se desactivó ninguno; las filas del archivo sí se escribieron. Vuelve a subir el archivo para revisarlo.");
    }
}
