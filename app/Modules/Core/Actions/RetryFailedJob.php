<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vuelve a mandar a su cola un trabajo fallido, con `queue:retry` de Laravel (que lo saca de `failed_jobs`, lo encola con
 * sus intentos en cero y lo olvida). Quién puede lo decide la pantalla con FailedJobPolicy (CF-S8). Todo va en una
 * transacción: primero la bitácora (con el trabajo, la cola y el error) y luego `queue:retry`; si este no lo saca de los
 * fallidos, lanza y la bitácora se deshace. Si el trabajo ya no existe (otro lo reintentó o lo descartó), no escribe nada.
 */
final class RetryFailedJob
{
    public function handle(FailedJob $job, ?User $actor = null): void
    {
        DB::transaction(function () use ($job, $actor): void {
            $job = FailedJob::query()->where('uuid', $job->uuid)->lockForUpdate()->first();

            if ($job === null) {
                return;
            }

            activity()
                ->causedBy($actor)
                ->event('failed-job-retried')
                ->withProperties(['uuid' => $job->uuid, 'job' => $job->job_name, 'queue' => $job->queue, 'error' => $job->summary()])
                ->log("Reintentó el trabajo fallido {$job->job_name} de la cola {$job->queue}");

            $exit = Artisan::call('queue:retry', ['id' => [$job->uuid]]);

            if ($exit !== 0 || FailedJob::query()->where('uuid', $job->uuid)->exists()) {
                throw new RuntimeException("No se pudo reintentar el trabajo {$job->uuid}.");
            }
        });
    }
}
