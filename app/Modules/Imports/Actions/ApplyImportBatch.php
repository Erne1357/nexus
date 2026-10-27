<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Aplica un lote validado: escribe en el catálogo, con ImportTemplate::apply(), las filas válidas y
 * las que traen advertencia, en el orden del archivo y de 500 en 500, una transacción por bloque. Las
 * filas con error se saltan (reglas-de-negocio.md, "Reglas de datos"). Cada fila escrita pasa a
 * `applied` con el registro que escribió; al terminar corre afterApply() de la plantilla y el lote
 * queda `applied`.
 *
 * Deja **una** entrada de bitácora por lote, con altas, cambios, filas sin cambios y filas saltadas,
 * y avisa con BatchApplied después del commit; los registros del catálogo no reciben entrada propia
 * (decisión del 27 de octubre de 2026). No sabe de roles: quién aplica lo decide la página con
 * ImportBatchPolicy.
 *
 * Si una fila no se puede escribir, su bloque se revierte, el lote queda `failed` con la fila y el
 * motivo, y el error se relanza. Los bloques anteriores se quedan escritos: el upsert por clave
 * natural hace que volver a importar el archivo corregido sea seguro.
 */
final class ApplyImportBatch
{
    private const int CHUNK_ROWS = 500;

    public function __construct(private readonly TemplateRegistry $templates) {}

    /**
     * @throws LogicException si el lote no está `validated` (otra corrida lo tomó o ya se aplicó)
     */
    public function handle(ImportBatch $batch, User $user): ImportBatch
    {
        $this->start($batch);

        $template = $this->templates->get($batch->template);
        $context = new ImportContext($user, $batch->campus);
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $current = null;

        try {
            ImportRow::query()
                ->where('batch_id', $batch->id)
                ->whereIn('status', [RowStatus::Valid, RowStatus::Warning])
                // Por row_number y no por offset: cada fila sale del filtro al pasar a `applied`, y un
                // chunk() por páginas se saltaría la mitad.
                ->chunkById(self::CHUNK_ROWS, function (Collection $rows) use ($template, $context, &$counts, &$current): void {
                    DB::transaction(function () use ($rows, $template, $context, &$counts, &$current): void {
                        foreach ($rows as $row) {
                            $current = $row;
                            $target = $template->apply($row->normalized, $context);

                            $counts[match (true) {
                                $target->wasRecentlyCreated => 'created',
                                $target->wasChanged() => 'updated',
                                default => 'unchanged',
                            }]++;

                            $row->update([
                                'status' => RowStatus::Applied,
                                'target_type' => $target->getMorphClass(),
                                'target_id' => $target->getKey(),
                            ]);
                        }
                    });
                }, 'row_number');

            $template->afterApply($context);
        } catch (Throwable $exception) {
            $where = $current === null ? '' : " en la fila {$current->row_number}";
            $this->fail($batch, Str::limit("La aplicación se interrumpió{$where}: {$exception->getMessage()}", 1000));

            throw $exception;
        }

        return $this->finish($batch, $user, $template, [...$counts, 'skipped' => $batch->error_rows]);
    }

    /**
     * Pasa el lote de `validated` a `applying` con el registro bloqueado: dos clics en "aplicar" no
     * lo aplican dos veces.
     */
    private function start(ImportBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->status !== BatchStatus::Validated) {
                throw new LogicException("El lote {$batch->id} no está validado: está {$locked->status->label()}.");
            }

            $locked->update(['status' => BatchStatus::Applying]);
            $batch->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * @param  array{created: int, updated: int, unchanged: int, skipped: int}  $counts
     */
    private function finish(ImportBatch $batch, User $user, ImportTemplate $template, array $counts): ImportBatch
    {
        return DB::transaction(function () use ($batch, $user, $template, $counts): ImportBatch {
            $batch->update([
                'status' => BatchStatus::Applied,
                'applied_by' => $user->id,
                'applied_at' => now(),
            ]);

            activity()
                ->performedOn($batch)
                ->causedBy($user)
                ->event('applied')
                ->withProperties(['template' => $batch->template, 'campus_id' => $batch->campus_id, ...$counts])
                ->log("Aplicó {$batch->original_name} en {$template->label()}: {$counts['created']} altas, {$counts['updated']} cambios, {$counts['unchanged']} sin cambios y {$counts['skipped']} filas con error sin aplicar");

            BatchApplied::dispatch($batch, $counts);

            return $batch;
        });
    }

    private function fail(ImportBatch $batch, string $reason): void
    {
        DB::transaction(function () use ($batch, $reason): void {
            $batch->update(['status' => BatchStatus::Failed, 'failure_reason' => $reason]);

            activity()
                ->performedOn($batch)
                ->event('failed')
                ->withProperties(['failure_reason' => $reason])
                ->log("No se pudo aplicar {$batch->original_name}: {$reason}");
        });
    }
}
