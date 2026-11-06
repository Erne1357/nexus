<?php

namespace App\Modules\Imports\Sii;

/**
 * Cuentas de un lote migrado; nunca valores.
 */
final readonly class SiiBatchReport
{
    public function __construct(
        public string $template,
        public string $file,
        public int $rows,
        public int $valid,
        public int $warnings,
        public int $errors,
        public int $created,
        public int $changed,
    ) {}
}
