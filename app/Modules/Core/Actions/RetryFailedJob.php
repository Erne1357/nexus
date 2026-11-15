<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Vuelve a mandar a su cola un trabajo fallido, con `queue:retry` de Laravel (que lo saca de `failed_jobs`, lo
 * encola con sus intentos en cero y lo olvida). Quién puede lo decide la pantalla con FailedJobPolicy (CF-S8). Deja
 * bitácora, con el trabajo, la cola y el error: si ya no existe (otro lo reintentó o lo descartó), no escribe nada.
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

            Artisan::call('queue:retry', ['id' => [$job->uuid]]);

            activity()
                ->causedBy($actor)
                ->event('failed-job-retried')
                ->withProperties(['uuid' => $job->uuid, 'job' => $job->job_name, 'queue' => $job->queue, 'error' => $job->summary()])
                ->log("Reintentó el trabajo fallido {$job->job_name} de la cola {$job->queue}");
        });
    }
}
