<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Queries\ImportBatchQuery;
use Illuminate\Support\Facades\DB;

/**
 * Saca de la cola un lote atorado (ImportBatchQuery::stalled(); E-03, ensayo 8.11) para que se pueda volver a
 * encolar. Pasa cuando el proceso de la cola murió sin que corriera el failed() de su job (sin memoria, un despliegue),
 * así que InterruptImportBatch no lo dejó fallido. Lo revisa con el registro bloqueado, porque la página pudo quedarse
 * vieja, y quita la corrida (`run_id`): si la cola llega a dar por fallido el job muerto, InterruptImportBatch ya no lo
 * reconoce y no lo toca. Según la etapa (supuesto por confirmar):
 *
 * - Validando: lo deja `uploaded`, para validar otra vez el mismo archivo desde el principio. No borra las filas que
 *   alcanzó a leer: con un kárdex son cientos de miles y tardaría en la petición; las borra el job al empezar
 *   (ValidateImportBatch::start()). La validación solo escribe staging, así que repetirla es seguro.
 * - Aplicando: lo deja `validated` para reanudar. Cada bloque escribe sus filas y las marca `applied` en la misma
 *   transacción, y ApplyImportBatch solo toma las que siguen válidas o con advertencia: lo escrito no se repite y las
 *   cuentas del lote siguen sumando desde ahí. El lote sigue siendo de quien lo empezó a aplicar (`applied_by`, que
 *   ApplyImportBatch anota al empezar y no cambia); quien reanuda queda en la bitácora `retried` y escribe a su nombre
 *   lo que falta.
 * - Aplicando con «desactivar faltantes»: no se reanuda, porque desactivar pide la cifra que el admin confirmó y el lote
 *   no la guarda. Tampoco vuelve a `validated` para confirmarla otra vez: solo queda fallido, con FailImportBatch, que
 *   conserva lo escrito y lo dice en su bitácora y en BatchApplied (supuesto por confirmar).
 *
 * Solo actúa sobre un lote atorado. Una aplicación que ya quedó fallida (InterruptImportBatch, cuando la cola sí corrió
 * el failed() del job) no se reanuda aunque lo escrito se pudiera reanudar: se vuelve a subir el archivo (supuesto por
 * confirmar).
 *
 * No encola nada: la página encola la validación, la aplicación o, si quedó fallido, el archivo de errores, según el
 * estado que devuelve. No autoriza: la página lo hace con ImportBatchPolicy.
 */
final class RetryStalledBatch
{
    public const string DEACTIVATING_REASON = 'La aplicación se atoró sin avanzar: el proceso de la cola se detuvo antes de terminar. Lo que alcanzó a escribirse se queda. Como se eligió desactivar los que no vienen en el archivo, no se reanuda: vuelve a subir el archivo para revisar otra vez cuáles se desactivan.';

    public function __construct(
        private readonly ImportBatchQuery $batches,
        private readonly FailImportBatch $fail,
    ) {}

    /**
     * @return BatchStatus `uploaded` para encolar la validación, `validated` para encolar la aplicación o `failed`
     *
     * @throws BatchNotReady si el lote no está atorado: ya terminó, falló o sigue avanzando
     */
    public function handle(ImportBatch $batch, User $user): BatchStatus
    {
        return DB::transaction(function () use ($batch, $user): BatchStatus {
            $locked = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! $this->batches->stalled($locked)) {
                throw new BatchNotReady("El lote {$batch->id} no está atorado: está {$locked->status->label()}.");
            }

            match (true) {
                $locked->status === BatchStatus::Validating => $this->restartValidation($locked, $user),
                $locked->deactivate_missing => $this->fail->handle($locked, self::DEACTIVATING_REASON, $user),
                default => $this->resumeApplication($locked, $user),
            };

            $batch->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked->status;
        });
    }

    private function restartValidation(ImportBatch $batch, User $user): void
    {
        $read = $batch->processed_rows;

        $batch->update(['status' => BatchStatus::Uploaded, 'run_id' => null, 'processed_rows' => 0]);

        activity()
            ->performedOn($batch)
            ->causedBy($user)
            ->event('retried')
            ->withProperties(['stage' => BatchStatus::Validating->value, 'processed_rows' => $read])
            ->log("Volvió a validar {$batch->original_name}: la validación se atoró con {$read} filas leídas");
    }

    private function resumeApplication(ImportBatch $batch, User $user): void
    {
        $reviewed = $batch->processed_rows;

        $batch->update(['status' => BatchStatus::Validated, 'run_id' => null]);

        activity()
            ->performedOn($batch)
            ->causedBy($user)
            ->event('retried')
            ->withProperties(['stage' => BatchStatus::Applying->value, 'processed_rows' => $reviewed, 'valid_rows' => $batch->valid_rows, 'started_by' => $batch->applied_by])
            ->log("Reanudó la aplicación de {$batch->original_name}: se atoró con {$reviewed} de {$batch->valid_rows} filas revisadas");
    }
}
