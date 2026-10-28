<?php

namespace App\Modules\Imports\Queries;

use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;

/**
 * Lecturas de lotes de importación. Quién las ve lo decide la página con ImportBatchPolicy.
 */
final class ImportBatchQuery
{
    /**
     * Lo que la aplicación del lote lleva escrito (las cuentas que ApplyImportBatch suma en cada bloque)
     * y las filas con error que no se aplican. Lo usan la bitácora, BatchApplied y la pantalla.
     *
     * @return array{created: int, updated: int, unchanged: int, skipped: int}
     */
    public function appliedCounts(ImportBatch $batch): array
    {
        return [
            'created' => $batch->created_rows,
            'updated' => $batch->updated_rows,
            'unchanged' => $batch->unchanged_rows,
            'skipped' => ImportRow::query()->where('batch_id', $batch->id)->where('status', RowStatus::Error)->count(),
        ];
    }
}
