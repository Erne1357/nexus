<?php

namespace App\Modules\Imports\Sii;

/**
 * Cuentas de un lote migrado; nunca valores.
 *
 * Un paso que escribe por su cuenta (SiiStep) puede traer además sus incidencias, que la corrida agrega a
 * `_incidencias.csv` a nombre del paso y del archivo del reporte, y notas para la consola, que el comando imprime debajo
 * de la tabla: solo cuentas, nunca un valor de una persona.
 */
final readonly class SiiBatchReport
{
    /**
     * @param  list<array{level: 'error'|'warning'|'info', ref: string, message: string}>  $incidents
     * @param  list<string>  $notes
     */
    public function __construct(
        public string $template,
        public string $file,
        public int $rows,
        public int $valid,
        public int $warnings,
        public int $errors,
        public int $created,
        public int $changed,
        public array $incidents = [],
        public array $notes = [],
    ) {}
}
