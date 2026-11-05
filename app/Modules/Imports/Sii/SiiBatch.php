<?php

namespace App\Modules\Imports\Sii;

/**
 * Un lote que un paso entrega al motor de importación: las filas ya en los campos de la plantilla.
 */
final readonly class SiiBatch
{
    /**
     * @param  string  $template  llave de la plantilla de importación
     * @param  string  $file  nombre del CSV intermedio que se escribe en `_nexus/`
     * @param  iterable<array<string, scalar|null>>  $rows  una fila por registro, con los campos de la plantilla
     * @param  string|null  $campus  código del campus (`C1`, `C2`) si la plantilla es de campus
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents  lo que el paso descartó o corrigió; `ref` identifica la fila sin datos personales
     */
    public function __construct(
        public string $template,
        public string $file,
        public iterable $rows,
        public ?string $campus = null,
        public array $incidents = [],
    ) {}
}
