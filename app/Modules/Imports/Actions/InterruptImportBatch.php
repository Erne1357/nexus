<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchApplied;
use App\Modules\Imports\Events\BatchValidated;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use Illuminate\Support\Facades\DB;

/**
 * Deja fallido un lote cuya validación o aplicación se cortó sin que la Action alcanzara a registrarlo:
 * el proceso de la cola murió o pasó del tiempo máximo del job. Solo actúa si el lote sigue en la etapa
 * que se cortó (`$running`); un lote que ya terminó, o que su Action ya dejó fallido con su propio
 * motivo, no se toca.
 *
 * Igual que las Actions que se cortaron: una validación interrumpida no se queda con filas a medias y
 * avisa con BatchValidated; una aplicación interrumpida conserva lo que alcanzó a escribir, que sale de
 * las cuentas que ApplyImportBatch suma al lote en cada bloque, y avisa con BatchApplied. Las dos dejan
 * bitácora a nombre de `$causer`: quien subió o quien aplicó el lote.
 */
final class InterruptImportBatch
{
    public function handle(ImportBatch $batch, BatchStatus $running, User $causer): void
    {
        DB::transaction(function () use ($batch, $running, $causer): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->status !== $running) {
                return;
            }

            match ($running) {
                BatchStatus::Validating => $this->interruptValidation($locked, $causer),
                BatchStatus::Applying => $this->interruptApplication($locked, $causer),
            };

            $batch->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    private function interruptValidation(ImportBatch $batch, User $causer): void
    {
        $reason = 'La validación se interrumpió antes de terminar: el proceso de la cola se detuvo o pasó del tiempo máximo. Vuelve a subir el archivo; si se repite, avisa al equipo de Nexus.';

        ImportRow::query()->where('batch_id', $batch->id)->delete();
        $batch->update(['status' => BatchStatus::Failed, 'failure_reason' => $reason]);

        activity()
            ->performedOn($batch)
            ->causedBy($causer)
            ->event('failed')
            ->withProperties(['failure_reason' => $reason])
            ->log("No se pudo validar {$batch->original_name}: {$reason}");

        BatchValidated::dispatch($batch);
    }

    private function interruptApplication(ImportBatch $batch, User $causer): void
    {
        $reason = 'La aplicación se interrumpió antes de terminar: el proceso de la cola se detuvo o pasó del tiempo máximo. Lo que alcanzó a escribirse se queda; vuelve a subir el archivo para terminar.';
        $counts = [
            'created' => $batch->created_rows,
            'updated' => $batch->updated_rows,
            'unchanged' => $batch->unchanged_rows,
            'skipped' => ImportRow::query()->where('batch_id', $batch->id)->where('status', RowStatus::Error)->count(),
        ];

        $batch->update(['status' => BatchStatus::Failed, 'failure_reason' => $reason]);

        activity()
            ->performedOn($batch)
            ->causedBy($causer)
            ->event('failed')
            ->withProperties(['failure_reason' => $reason, ...$counts])
            ->log("No se pudo aplicar {$batch->original_name}: {$reason}");

        BatchApplied::dispatch($batch, $counts);
    }
}
