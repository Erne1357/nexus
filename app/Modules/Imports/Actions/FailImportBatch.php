<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Events\BatchValidated;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Queries\ImportBatchQuery;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Deja fallido, con el motivo que verá el admin, un lote que se cortó a media validación o a media
 * aplicación. Es la única forma en que un lote queda fallido: la usan ValidateImportBatch y
 * ApplyImportBatch cuando algo sale mal, e InterruptImportBatch cuando el job de la cola se cortó sin que
 * la Action alcanzara a hacerlo. La etapa sale del estado del lote.
 *
 * Una validación fallida no se queda con filas a medias ni con avance: sus filas son staging que no se
 * aplica. Una aplicación fallida conserva lo que alcanzó a escribir, y su bitácora y BatchApplied lo
 * dicen con las cuentas que ApplyImportBatch suma al lote en cada bloque. Las dos dejan bitácora a
 * nombre de `$causer` (quien subió o quien aplicó el lote) y avisan después del commit.
 */
final class FailImportBatch
{
    public function __construct(private readonly ImportBatchQuery $batches) {}

    /**
     * @throws LogicException si el lote no se está validando ni aplicando
     */
    public function handle(ImportBatch $batch, string $reason, User $causer): void
    {
        DB::transaction(function () use ($batch, $reason, $causer): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            match ($locked->status) {
                BatchStatus::Validating => $this->failValidation($locked, $reason, $causer),
                BatchStatus::Applying => $this->failApplication($locked, $reason, $causer),
                default => throw new LogicException("El lote {$batch->id} no se está validando ni aplicando: está {$locked->status->label()}."),
            };

            $batch->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    private function failValidation(ImportBatch $batch, string $reason, User $causer): void
    {
        ImportRow::query()->where('batch_id', $batch->id)->delete();
        $batch->update(['status' => BatchStatus::Failed, 'failure_reason' => $reason, 'processed_rows' => 0]);

        activity()
            ->performedOn($batch)
            ->causedBy($causer)
            ->event('failed')
            ->withProperties(['failure_reason' => $reason])
            ->log("No se pudo validar {$batch->original_name}: {$reason}");

        BatchValidated::dispatch($batch);
    }

    private function failApplication(ImportBatch $batch, string $reason, User $causer): void
    {
        $counts = $this->batches->appliedCounts($batch);

        $batch->update(['status' => BatchStatus::Failed, 'failure_reason' => $reason]);

        activity()
            ->performedOn($batch)
            ->causedBy($causer)
            ->event('failed')
            ->withProperties(['failure_reason' => $reason, ...$counts])
            ->log("No se pudo aplicar {$batch->original_name}: {$reason}");

        // Lo ya escrito se queda: quien escuche el catálogo tiene que enterarse igual.
        BatchApplied::dispatch($batch, $counts);
    }
}
