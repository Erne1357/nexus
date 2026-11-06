<?php

namespace App\Modules\Imports\Sii;

/**
 * Un paso de la migración del SII: lee la exportación y arma uno o más lotes para el motor de importación.
 * Los pasos corren en el orden de `ImportsServiceProvider::SII_STEPS`.
 */
interface SiiStep
{
    /**
     * Nombre corto del paso, el que se pasa a `--only` (por ejemplo `departamentos`).
     */
    public function name(): string;

    /**
     * @return iterable<SiiBatch>
     */
    public function batches(SiiMigration $migration): iterable;
}
