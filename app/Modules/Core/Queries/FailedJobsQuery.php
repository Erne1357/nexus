<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\FailedJob;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Los trabajos fallidos de la cola, del más reciente al más viejo, para «Correos y colas» (CF-S8): cola, nombre del
 * trabajo, hora y las primeras líneas de la excepción (FailedJob).
 */
final class FailedJobsQuery
{
    /**
     * @return LengthAwarePaginator<int, FailedJob>
     */
    public function forTable(int $perPage = 15): LengthAwarePaginator
    {
        return FailedJob::query()->orderByDesc('failed_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @throws ModelNotFoundException<FailedJob>
     */
    public function findOrFail(string $uuid): FailedJob
    {
        return FailedJob::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * El trabajo fallido con ese uuid, o null si ya no está (otro lo reintentó o lo descartó).
     */
    public function firstByUuid(string $uuid): ?FailedJob
    {
        return FailedJob::query()->where('uuid', $uuid)->first();
    }
}
