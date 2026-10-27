<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Queries\ImportBatchQuery;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga las filas con error de un lote como CSV, para que el admin las corrija y vuelva a subir el
 * archivo (reglas-de-negocio.md, "Reglas de datos"). Cada fila sale como vino en el archivo, con el
 * número de fila al principio y sus errores al final, con la etiqueta de cada campo. UTF-8 con BOM
 * para que Excel abra bien los acentos.
 *
 * Es una ruta y no una acción de Livewire porque se transmite de a poco: Livewire juntaría el archivo
 * entero en memoria, y un kardex mal mapeado puede traer cientos de miles de filas con error. Quién
 * descarga lo decide ImportBatchPolicy (`view`, solo el admin). El id llega en la URL y se busca con
 * KeyLookup: uno que no es uuid es un 404, no un error de PostgreSQL.
 */
final class DownloadBatchErrors
{
    public function __invoke(string $batch, ImportBatchQuery $batches, TemplateRegistry $templates): StreamedResponse
    {
        $batch = $batches->findOrFail($batch);
        Gate::authorize('view', $batch);

        // Un lote viejo pudo quedarse sin plantilla registrada: entonces se usa el nombre del campo.
        $labels = $templates->has($batch->template) ? $templates->get($batch->template)->fields() : [];
        $rows = $batches->errorRows($batch);

        return response()->streamDownload(function () use ($rows, $labels): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\u{FEFF}");
            $columns = null;

            foreach ($rows as $row) {
                if ($columns === null) {
                    $columns = array_map(strval(...), array_keys($row->raw));
                    fputcsv($output, ['Fila', ...$columns, 'Errores'], escape: '');
                }

                fputcsv($output, [
                    $row->row_number,
                    ...array_map(fn (string $column): mixed => $row->raw[$column] ?? null, $columns),
                    $this->describeErrors($row, $labels),
                ], escape: '');
            }

            if ($columns === null) {
                fputcsv($output, ['Fila', 'Errores'], escape: '');
            }

            fclose($output);
        }, pathinfo($batch->original_name, PATHINFO_FILENAME).' - errores.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * "campo: mensaje. campo: mensaje. otro mensaje.", con la etiqueta del campo.
     *
     * @param  array<string, string>  $labels
     */
    private function describeErrors(ImportRow $row, array $labels): string
    {
        $parts = [];

        foreach ($row->errors as $field => $messages) {
            $parts[] = ($labels[$field] ?? $field).': '.implode(' ', $messages);
        }

        return implode(' ', $parts);
    }
}
