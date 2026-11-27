<?php

namespace App\Modules\Imports\Sii;

/**
 * Un paso de la migración del SII: lee la exportación y arma uno o más lotes para el motor de importación.
 * Los pasos corren en el orden de `ImportsServiceProvider::SII_STEPS`.
 *
 * Un paso que escribe por su cuenta, sin el motor (el archivo del SII, ArchiveStep), entrega en lugar de lotes el
 * reporte de lo que escribió (SiiBatchReport), que la corrida agrega tal cual; en un ensayo (`SiiMigration::dryRun()`)
 * no escribe y reporta lo que escribiría.
 */
interface SiiStep
{
    /**
     * Nombre corto del paso, el que se pasa a `--only` (por ejemplo `departamentos`).
     */
    public function name(): string;

    /**
     * @return iterable<SiiBatch|SiiBatchReport>
     */
    public function batches(SiiMigration $migration): iterable;
}
