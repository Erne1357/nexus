<?php

namespace App\Modules\Imports\Sii;

use RuntimeException;
use Throwable;

/**
 * La corrida se detuvo en un paso y lote. El mensaje es fijo y no trae valores: la causa original (que puede
 * traer datos personales, como el SQL de una QueryException) queda en `getPrevious()` y solo va al log.
 */
final class SiiMigrationStopped extends RuntimeException
{
    public function __construct(
        public readonly string $step,
        public readonly ?string $batchFile,
        public readonly string $cause,
        public readonly int $appliedBatches,
        ?Throwable $previous = null,
    ) {
        parent::__construct("La migración se detuvo en el paso {$step}".($batchFile === null ? '' : ", lote {$batchFile}").": {$cause}.", 0, $previous);
    }
}
