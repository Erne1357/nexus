<?php

namespace App\Modules\Imports\Events;

use App\Modules\Imports\Models\ImportMapping;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se guardó un mapeo de columnas (CreateImportMapping). Sale después del commit; como toda Action de
 * alta, avisa aunque todavía no lo escuche nadie.
 */
final class ImportMappingCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ImportMapping $mapping,
    ) {}
}
