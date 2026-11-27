<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Queries\ImportBatchQuery;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Storage;

/**
 * Escribe en el disco `imports` (`$batch->errors_path`) el CSV con las filas con error de un lote,
 * para que el admin las corrija y vuelva a subir el archivo (reglas-de-negocio.md, "Reglas de datos").
 * Cada fila sale como vino, con su número al principio y sus errores al final, con la etiqueta de
 * cada campo; los nombres de esas dos columnas no chocan con los del SII, así que el archivo corregido
 * se vuelve a subir tal cual. UTF-8 con BOM para que Excel abra bien los acentos.
 *
 * La llaman los jobs de la cola al terminar de validar y de aplicar (la aplicación puede sumar filas
 * con error): un kardex mal mapeado puede traer cientos de miles, y armarlo en la petición pasaría del
 * segundo que la arquitectura permite. Escribe aparte y reemplaza el archivo al final, así que una
 * descarga nunca ve uno a medias; después anota en el lote cuándo quedó listo (`errors_exported_at`),
 * que ApplyImportBatch borra al empezar. No deja bitácora: no cambia los datos del lote ni el catálogo.
 *
 * Un texto que empieza con `=`, `+`, `-` o `@` y no es un número sale con un apóstrofo delante, para
 * que Excel no lo ejecute como fórmula.
 */
final class ExportBatchErrors
{
    private const string ROW_COLUMN = 'Fila del archivo';

    private const string ERRORS_COLUMN = 'Errores en Nexus';

    public function __construct(
        private readonly ImportBatchQuery $batches,
        private readonly TemplateRegistry $templates,
    ) {}

    public function handle(ImportBatch $batch): void
    {
        $disk = Storage::disk('imports');
        $temporary = $disk->path("{$batch->errors_path}.tmp");
        // Un lote viejo pudo quedarse sin plantilla registrada: entonces se usa el nombre del campo.
        $labels = $this->templates->has($batch->template) ? $this->templates->get($batch->template)->fields() : [];

        $output = fopen($temporary, 'w');
        fwrite($output, "\u{FEFF}");
        $columns = null;

        foreach ($this->batches->errorRows($batch) as $row) {
            if ($columns === null) {
                $columns = array_map(strval(...), array_keys($row->raw));
                fputcsv($output, [self::ROW_COLUMN, ...$columns, self::ERRORS_COLUMN], escape: '');
            }

            fputcsv($output, array_map($this->cell(...), [
                $row->row_number,
                ...array_map(fn (string $column): mixed => $row->raw[$column] ?? null, $columns),
                $this->describeErrors($row, $labels),
            ]), escape: '');
        }

        if ($columns === null) {
            fputcsv($output, [self::ROW_COLUMN, self::ERRORS_COLUMN], escape: '');
        }

        fclose($output);
        rename($temporary, $disk->path($batch->errors_path));

        // Sin tocar updated_at: es una marca del archivo, no un cambio del lote.
        $exportedAt = now();
        ImportBatch::query()->whereKey($batch->id)->toBase()->update(['errors_exported_at' => $exportedAt]);
        $batch->forceFill(['errors_exported_at' => $exportedAt])->syncOriginalAttribute('errors_exported_at');
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

    private function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'{$value}";
        }

        return $value;
    }
}
