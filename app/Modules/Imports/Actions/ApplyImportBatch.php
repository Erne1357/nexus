<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportDeactivation;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Queries\ImportBatchQuery;
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
 * filas con error se saltan (reglas-de-negocio.md, "Reglas de datos"). Antes de escribir cada fila la
 * vuelve a validar, porque el catálogo pudo cambiar desde la validación (un registro borrado, una
 * clave cruzada que ya no existe): la que ya no pasa queda como error y se salta. Cada fila escrita
 * pasa a `applied` con el registro que escribió. Cada bloque suma al lote, en su misma transacción, las
 * filas revisadas (`processed_rows`, el avance que muestra la pantalla) y las altas, cambios y filas sin
 * cambios ya escritas. Al terminar corre afterApply() de la plantilla y el lote queda `applied`.
 *
 * Si el admin eligió "desactivar faltantes" para el lote (tarea 1.3), después del último bloque, y solo
 * si todos se escribieron, desactiva en su propia transacción los registros que no vienen en el archivo
 * (ImportTemplate::missing()), guarda cuáles fueron en `import_deactivations` y suma `deactivated_rows`.
 * Es como un último bloque: si después algo falla, lo desactivado se queda y el fallo lo cuenta. Solo
 * cambia `is_active`, sin pasar por el modelo, así que los registros no reciben bitácora propia.
 *
 * Deja **una** entrada de bitácora por lote, a nombre de quien aplica, con altas, cambios, filas sin
 * cambios, filas saltadas y, si se eligió, cuántos desactivó, y avisa con BatchApplied después del
 * commit. Los registros del catálogo no reciben entrada propia (decisión del 27 de octubre de 2026). No
 * sabe de roles: quién aplica lo decide la página con ImportBatchPolicy.
 *
 * Si una fila no se puede escribir, su bloque se revierte, el lote queda `failed` con la fila y el
 * motivo, y el error se relanza. Los bloques anteriores se quedan escritos, así que la bitácora del
 * fallo y BatchApplied llevan lo que sí se escribió (FailImportBatch); volver a importar el archivo
 * corregido es seguro por el upsert por clave natural. Si el proceso de la cola muere a media
 * aplicación, ApplyQueuedBatch deja el lote fallido con InterruptImportBatch, que lee lo escrito de esas
 * mismas cuentas.
 */
final class ApplyImportBatch
{
    private const int CHUNK_ROWS = 500;

    public function __construct(
        private readonly TemplateRegistry $templates,
        private readonly ImportBatchQuery $batches,
        private readonly FailImportBatch $failure,
    ) {}

    /**
     * @param  string|null  $run  la corrida de la cola que aplica (ApplyQueuedBatch), que queda en el lote
     * @param  bool  $deactivateMissing  si el admin eligió "desactivar faltantes" para este lote
     *
     * @throws BatchNotReady si el lote no está `validated` (otra corrida lo tomó, ya se aplicó o falló)
     * @throws LogicException si se pide desactivar faltantes con una plantilla que no lo ofrece; el lote
     *                        sigue validado
     */
    public function handle(ImportBatch $batch, User $user, ?string $run = null, bool $deactivateMissing = false): ImportBatch
    {
        $template = $this->templates->get($batch->template);

        if ($deactivateMissing && ! $template->deactivatesMissing()) {
            throw new LogicException("La plantilla {$template->key()} no desactiva faltantes.");
        }

        $this->start($batch, $run, $deactivateMissing);

        $context = new ImportContext($user, $batch->campus);
        $current = null;

        try {
            ImportRow::query()
                ->select(['id', 'batch_id', 'row_number', 'normalized', 'status'])
                ->where('batch_id', $batch->id)
                ->whereIn('status', [RowStatus::Valid, RowStatus::Warning])
                // Por row_number y no por offset: cada fila sale del filtro al pasar a `applied`, y un
                // chunk() por páginas se saltaría la mitad.
                ->chunkById(self::CHUNK_ROWS, function (Collection $rows) use ($batch, $template, $context, &$current): void {
                    DB::transaction(function () use ($batch, $rows, $template, $context, &$current): void {
                        $written = $this->applyChunk($rows, $template, $context, $current);

                        ImportBatch::query()->whereKey($batch->id)->incrementEach([
                            'processed_rows' => $rows->count(),
                            'created_rows' => $written['created'],
                            'updated_rows' => $written['updated'],
                            'unchanged_rows' => $written['unchanged'],
                        ]);
                    });
                }, 'row_number');

            $current = null;

            if ($batch->deactivate_missing) {
                $this->deactivateMissing($batch, $template, $context);
            }

            $template->afterApply($context);

            return $this->finish($batch, $user, $template);
        } catch (Throwable $exception) {
            $where = $current === null ? '' : " en la fila {$current->row_number}";

            try {
                $this->failure->handle($batch, Str::limit("La aplicación se interrumpió{$where}: {$exception->getMessage()}", 1000), $user);
            } catch (Throwable $failure) {
                // Si la base ya no acepta escrituras, manda el error original.
                report($failure);
            }

            throw $exception;
        }
    }

    /**
     * Escribe un bloque y marca sus filas, dentro de la transacción del bloque.
     *
     * @param  Collection<int, ImportRow>  $rows
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function applyChunk(Collection $rows, ImportTemplate $template, ImportContext $context, ?ImportRow &$current): array
    {
        $written = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $applied = [];

        foreach ($rows as $row) {
            $current = $row;
            $issues = $template->validate($row->normalized, $context);

            if ($issues->hasErrors()) {
                $row->update(['status' => RowStatus::Error, 'errors' => $issues->errors]);

                continue;
            }

            $target = $template->apply($row->normalized, $context);

            $written[match (true) {
                $target->wasRecentlyCreated => 'created',
                $target->wasChanged() => 'updated',
                default => 'unchanged',
            }]++;

            $applied[] = [$row->id, $target->getMorphClass(), $target->getKey()];
        }

        $this->markApplied($applied);

        return $written;
    }

    /**
     * Pasa a `applied` las filas escritas de un bloque en un solo UPDATE.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $applied  id de la fila, tipo y id del registro
     */
    private function markApplied(array $applied): void
    {
        if ($applied === []) {
            return;
        }

        $values = implode(', ', array_fill(0, count($applied), '(?::uuid, ?, ?::uuid)'));

        DB::update(
            "UPDATE import_rows AS r SET status = ?, target_type = v.target_type, target_id = v.target_id, updated_at = now()
             FROM (VALUES {$values}) AS v(id, target_type, target_id)
             WHERE r.id = v.id",
            [RowStatus::Applied->value, ...array_merge(...$applied)],
        );
    }

    /**
     * Desactiva lo que no vino en el archivo y anota cuáles fueron, en una transacción.
     */
    private function deactivateMissing(ImportBatch $batch, ImportTemplate $template, ImportContext $context): void
    {
        DB::transaction(function () use ($batch, $template, $context): void {
            $missing = $template->missing($batch, $context);
            $model = $missing->getModel();
            $key = $missing->getQuery()->getGrammar()->wrap($model->getQualifiedKeyName());

            ImportDeactivation::query()->insertUsing(
                ['batch_id', 'target_type', 'target_id'],
                $missing->toBase()->selectRaw("?::uuid, ?, {$key}", [$batch->id, $model->getMorphClass()]),
            );

            $deactivated = $model->newQuery()
                ->whereIn($model->getQualifiedKeyName(), ImportDeactivation::query()->where('batch_id', $batch->id)->select('target_id'))
                ->update(['is_active' => false]);

            ImportBatch::query()->whereKey($batch->id)->update(['deactivated_rows' => $deactivated]);
        });
    }

    /**
     * Pasa el lote de `validated` a `applying` con el registro bloqueado: dos clics en "aplicar" no
     * lo aplican dos veces. El avance vuelve a cero: al validar contó las filas leídas. Queda en el lote
     * si el admin eligió desactivar faltantes.
     */
    private function start(ImportBatch $batch, ?string $run, bool $deactivateMissing): void
    {
        DB::transaction(function () use ($batch, $run, $deactivateMissing): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->status !== BatchStatus::Validated) {
                throw new BatchNotReady("El lote {$batch->id} no está validado: está {$locked->status->label()}.");
            }

            // El archivo de errores de la validación deja de valer: la cola lo rehace al terminar.
            $locked->update([
                'status' => BatchStatus::Applying,
                'run_id' => $run,
                'processed_rows' => 0,
                'errors_exported_at' => null,
                'deactivate_missing' => $deactivateMissing,
            ]);
            $batch->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * Cierra el lote con las cuentas que sumó cada bloque, leídas de la base: no pasaron por este modelo.
     */
    private function finish(ImportBatch $batch, User $user, ImportTemplate $template): ImportBatch
    {
        return DB::transaction(function () use ($batch, $user, $template): ImportBatch {
            $counts = $this->batches->appliedCounts($batch->refresh());

            $batch->update([
                'status' => BatchStatus::Applied,
                'applied_by' => $user->id,
                'applied_at' => now(),
            ]);

            $summary = "{$counts['created']} altas, {$counts['updated']} cambios, {$counts['unchanged']} sin cambios";
            $summary .= $batch->deactivate_missing
                ? ", {$counts['skipped']} filas con error sin aplicar y {$counts['deactivated']} desactivados por no venir en el archivo"
                : " y {$counts['skipped']} filas con error sin aplicar";

            activity()
                ->performedOn($batch)
                ->causedBy($user)
                ->event('applied')
                ->withProperties(['template' => $batch->template, 'campus_id' => $batch->campus_id, 'deactivate_missing' => $batch->deactivate_missing, ...$counts])
                ->log("Aplicó {$batch->original_name} en {$template->label()}: {$summary}");

            BatchApplied::dispatch($batch, $counts);

            return $batch;
        });
    }
}
