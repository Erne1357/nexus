<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Support\Facades\DB;

/**
 * Deja fallido (FailImportBatch) un lote cuya validación o aplicación se cortó sin que la Action
 * alcanzara a registrarlo: el proceso de la cola murió o pasó del tiempo máximo del job. Solo si el lote
 * sigue validándose o aplicándose **por esa corrida** (`run_id`): no toca un lote que ya terminó, uno
 * que su Action ya dejó fallido con su propio motivo, ni uno que tomó otra corrida, como cuando falla un
 * job duplicado mientras el bueno sigue.
 */
final class InterruptImportBatch
{
    public function __construct(private readonly FailImportBatch $fail) {}

    public function handle(ImportBatch $batch, string $run, User $causer): void
    {
        DB::transaction(function () use ($batch, $run, $causer): void {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->run_id !== $run) {
                return;
            }

            $reason = match ($locked->status) {
                BatchStatus::Validating => 'La validación se interrumpió antes de terminar: el proceso de la cola se detuvo o pasó del tiempo máximo. Vuelve a subir el archivo; si se repite, avisa al equipo de Nexus.',
                BatchStatus::Applying => 'La aplicación se interrumpió antes de terminar: el proceso de la cola se detuvo o pasó del tiempo máximo. Lo que alcanzó a escribirse se queda; vuelve a subir el archivo para terminar.',
                default => null,
            };

            if ($reason !== null) {
                $this->fail->handle($batch, $reason, $causer);
            }
        });
    }
}
