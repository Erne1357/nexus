<?php

namespace App\Modules\Imports\Events;

use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se aplicó un lote de importación (ApplyImportBatch), entero o hasta donde llegó si falló a la
 * mitad: lo escrito se queda, y `counts` dice cuánto fue. Sale después del commit. Es como Imports
 * avisa a los módulos de su derecha en el mapa (CatalogValidation, fase 2) que un catálogo cambió.
 */
final class BatchApplied implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{created: int, updated: int, unchanged: int, skipped: int}  $counts
     */
    public function __construct(
        public ImportBatch $batch,
        public array $counts,
    ) {}
}
