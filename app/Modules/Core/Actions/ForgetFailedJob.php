<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\User;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Descarta un trabajo fallido: lo borra de `failed_jobs` con el failer de Laravel, sin volver a correrlo. Quién puede
 * lo decide la pantalla con FailedJobPolicy (CF-S8). Deja bitácora, con el trabajo, la cola y el error; si ya no
 * existe, no escribe nada. Los trabajos fallidos no son catálogo ni bitácora, así que el borrado físico es lo propio.
 */
final class ForgetFailedJob
{
    public function __construct(private readonly FailedJobProviderInterface $failer) {}

    public function handle(FailedJob $job, ?User $actor = null): void
    {
        DB::transaction(function () use ($job, $actor): void {
            $job = FailedJob::query()->where('uuid', $job->uuid)->lockForUpdate()->first();

            if ($job === null) {
                return;
            }

            $this->failer->forget($job->uuid);

            activity()
                ->causedBy($actor)
                ->event('failed-job-forgotten')
                ->withProperties(['uuid' => $job->uuid, 'job' => $job->job_name, 'queue' => $job->queue, 'error' => $job->summary()])
                ->log("Descartó el trabajo fallido {$job->job_name} de la cola {$job->queue}");
        });
    }
}
