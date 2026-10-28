<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Queries\ImportBatchQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga el CSV con las filas con error de un lote, el que dejó la cola con ExportBatchErrors al
 * terminar de validar o de aplicar; mientras no existe, responde 404. Quién descarga lo decide
 * ImportBatchPolicy (solo el admin), antes de buscar el lote, para no decirle a nadie más qué lotes
 * existen. El id llega en la URL y se busca con KeyLookup: uno que no es uuid es un 404, no un error de
 * PostgreSQL.
 */
final class DownloadBatchErrors
{
    public function __invoke(string $batch, ImportBatchQuery $batches): StreamedResponse
    {
        Gate::authorize('viewAny', ImportBatch::class);

        $batch = $batches->findOrFail($batch);
        Gate::authorize('view', $batch);

        $disk = Storage::disk('imports');
        abort_unless($disk->exists($batch->errors_path), 404);

        return $disk->download(
            $batch->errors_path,
            pathinfo($batch->original_name, PATHINFO_FILENAME).' - errores.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
