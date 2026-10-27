<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchValidated;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Readers\SpreadsheetReader;
use App\Modules\Imports\Readers\UnreadableFile;
use App\Modules\Imports\Templates\ColumnMatcher;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Valida un lote recién subido sin escribir en el catálogo. Lee el archivo en streaming, mapea sus
 * columnas a los campos de la plantilla (con el mapeo del lote o, sin él, por nombre de campo o
 * etiqueta sin importar mayúsculas, acentos ni espacios), normaliza y valida cada fila con la
 * plantilla y la guarda en `import_rows`, de 500 en 500, con sus errores y advertencias. Con cada
 * bloque suma `processed_rows`, el avance que la pantalla muestra mientras corre en la cola.
 *
 * Una fila sin error que repite la clave natural de otra anterior sin error del mismo archivo es error
 * y dice en qué fila está la primera: la que se aplica. Se encuentra en SQL por `key_hash` (la clave
 * como texto, así que 20111234 número y "20111234" texto son la misma), para no tener en memoria las
 * claves de un kardex completo. Al terminar, el lote queda `validated` con sus cuentas (las filas con
 * advertencia cuentan como válidas).
 *
 * Un archivo que no se puede leer, o sin la columna de la clave natural, deja el lote `failed` con el
 * motivo que verá el admin. Cualquier otro error lo deja `failed` y se relanza para que el job lo
 * registre. Un lote fallido no se queda con filas a medias. La bitácora del resultado es de quien subió
 * el lote, y avisa con BatchValidated después del commit. Si el proceso de la cola muere a media
 * validación, ValidateQueuedBatch deja el lote fallido con InterruptImportBatch.
 */
final class ValidateImportBatch
{
    private const int CHUNK_ROWS = 500;

    public function __construct(
        private readonly TemplateRegistry $templates,
        private readonly SpreadsheetReader $reader,
        private readonly ColumnMatcher $matcher,
        private readonly FailImportBatch $failure,
    ) {}

    /**
     * @param  string|null  $run  la corrida de la cola que valida (ValidateQueuedBatch), que queda en el lote
     *
     * @throws BatchNotReady si el lote ya no está `uploaded` (otra corrida lo tomó)
     */
    public function handle(ImportBatch $batch, ?string $run = null): ImportBatch
    {
        $this->start($batch, $run);

        try {
            $template = $this->templates->get($batch->template);
            $context = new ImportContext($batch->uploader, $batch->campus);
            $path = Storage::disk('imports')->path($batch->file_path);
            // Con borrados: el mapeo que eligió el admin al subir manda aunque después se haya retirado.
            $mapping = $batch->mapping()->withTrashed()->first();
            $columns = $this->columnsFor($template, $this->reader->header($path), $mapping);

            $pending = [];

            foreach ($this->reader->rows($path) as $number => $values) {
                $pending[] = $this->stagedRow($batch, $template, $context, $columns, $number, $values);

                if (count($pending) === self::CHUNK_ROWS) {
                    $this->stage($batch, $pending);
                    $pending = [];
                }
            }

            if ($pending !== []) {
                $this->stage($batch, $pending);
            }

            $this->markRepeatedKeys($batch, $template);

            return $this->finish($batch, $template);
        } catch (UnreadableFile $exception) {
            return $this->fail($batch, $exception->getMessage());
        } catch (Throwable $exception) {
            try {
                $this->fail($batch, 'La validación se interrumpió por un error inesperado. Vuelve a subir el archivo; si se repite, avisa al equipo de Nexus.');
            } catch (Throwable $failure) {
                // Si la base ya no acepta escrituras (transacción abortada), manda el error original.
                report($failure);
            }

            throw $exception;
        }
    }

    /**
     * Pasa el lote de `uploaded` a `validating` con el registro bloqueado: dos corridas del mismo lote
     * no validan dos veces.
     */
    private function start(ImportBatch $batch, ?string $run): void
    {
        DB::transaction(function () use ($batch, $run): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->status !== BatchStatus::Uploaded) {
                throw new BatchNotReady("El lote {$batch->id} ya no está subido: está {$locked->status->label()}.");
            }

            $locked->update(['status' => BatchStatus::Validating, 'run_id' => $run]);
            $batch->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * Guarda un bloque de filas y suma su avance al lote, juntos.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function stage(ImportBatch $batch, array $rows): void
    {
        DB::transaction(function () use ($batch, $rows): void {
            ImportRow::insert($rows);
            ImportBatch::query()->whereKey($batch->id)->increment('processed_rows', count($rows));
        });
    }

    /**
     * Columna del archivo → campo de la plantilla, con el mapeo del lote o, sin él, por nombre.
     *
     * @param  list<string>  $header
     * @return array<string, string>
     *
     * @throws UnreadableFile si falta una columna de la clave natural
     */
    private function columnsFor(ImportTemplate $template, array $header, ?ImportMapping $mapping): array
    {
        $columns = $this->matcher->match($template, $header, $mapping?->columns);

        if (($missing = $this->matcher->describeMissingKey($template, $columns)) !== null) {
            throw new UnreadableFile($missing);
        }

        return $columns;
    }

    /**
     * La fila lista para `import_rows`: insert en bloque, sin pasar por el modelo, así que los json van
     * codificados y el id lo pone la base (`uuidv7()`).
     *
     * @param  array<string, string>  $columns
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function stagedRow(ImportBatch $batch, ImportTemplate $template, ImportContext $context, array $columns, int $number, array $values): array
    {
        $mapped = [];

        foreach ($columns as $source => $field) {
            $mapped[$field] = $values[$source] ?? null;
        }

        $normalized = $template->normalize($mapped);
        $issues = $template->validate($normalized, $context);
        $key = $template->keyFor($normalized, $context);

        $status = match (true) {
            $issues->hasErrors() => RowStatus::Error,
            $issues->hasWarnings() => RowStatus::Warning,
            default => RowStatus::Valid,
        };

        return [
            'batch_id' => $batch->id,
            'row_number' => $number,
            'raw' => $this->json($values),
            'normalized' => $this->json($normalized),
            'errors' => $this->json((object) $issues->errors),
            'warnings' => $this->json((object) $issues->warnings),
            'status' => $status->value,
            'key_hash' => in_array(null, $key, true) ? null : hash('sha256', $this->json(array_map(strval(...), array_values($key)))),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Marca como error toda fila cuya clave natural ya vino en una fila anterior del mismo lote, con el
     * error en el primer campo de la clave.
     */
    private function markRepeatedKeys(ImportBatch $batch, ImportTemplate $template): void
    {
        DB::update(<<<'SQL'
            UPDATE import_rows AS r
            SET status = ?,
                errors = jsonb_set(r.errors, ARRAY[?], COALESCE(r.errors -> ?, '[]'::jsonb) || to_jsonb(format('La clave se repite: ya viene en la fila %s.', d.first_row))),
                updated_at = now()
            FROM (
                SELECT id, row_number, min(row_number) OVER (PARTITION BY key_hash) AS first_row
                FROM import_rows
                WHERE batch_id = ? AND key_hash IS NOT NULL AND status <> ?
            ) AS d
            WHERE r.id = d.id AND d.row_number <> d.first_row
            SQL, [RowStatus::Error->value, $template->naturalKey()[0], $template->naturalKey()[0], $batch->id, RowStatus::Error->value]);
    }

    private function finish(ImportBatch $batch, ImportTemplate $template): ImportBatch
    {
        $counts = ImportRow::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE status <> ?) AS valid, count(*) FILTER (WHERE status = ?) AS errors', [RowStatus::Error->value, RowStatus::Error->value])
            ->toBase()
            ->first();

        return DB::transaction(function () use ($batch, $template, $counts): ImportBatch {
            // Con el avance que sumó cada bloque, que no pasó por este modelo.
            $batch->refresh()->update([
                'status' => BatchStatus::Validated,
                'total_rows' => $counts->total,
                'valid_rows' => $counts->valid,
                'error_rows' => $counts->errors,
            ]);

            activity()
                ->performedOn($batch)
                ->causedBy($batch->uploader)
                ->event('validated')
                ->withProperties(['total_rows' => $batch->total_rows, 'valid_rows' => $batch->valid_rows, 'error_rows' => $batch->error_rows])
                ->log("Validó {$batch->original_name} para importar {$template->label()}: {$batch->valid_rows} de {$batch->total_rows} filas válidas");

            BatchValidated::dispatch($batch);

            return $batch;
        });
    }

    private function fail(ImportBatch $batch, string $reason): ImportBatch
    {
        $this->failure->handle($batch, $reason, $batch->uploader);

        return $batch;
    }

    private function json(mixed $value): string
    {
        if (is_array($value)) {
            $value = array_map(fn (mixed $cell): mixed => $cell instanceof DateTimeInterface ? $cell->format(DATE_ATOM) : $cell, $value);
        }

        // Un texto con UTF-8 inválido ya es error de su fila (validate()); aquí se sustituye para guardarla.
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
