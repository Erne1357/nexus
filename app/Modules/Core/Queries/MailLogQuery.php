<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\MailStatus;
use App\Modules\Core\Models\MailLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * El registro de correos de «Correos y colas» (CF-S8), del más reciente al más viejo: busca el texto en el destinatario
 * o el asunto y filtra por tipo, estado y fechas (de `$from` a `$to`, ambos días completos).
 */
final class MailLogQuery
{
    /**
     * @return LengthAwarePaginator<int, MailLog>
     */
    public function forTable(string $search = '', string $kind = '', ?MailStatus $status = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, int $perPage = 15): LengthAwarePaginator
    {
        return MailLog::query()
            ->with('user:id,username,name')
            ->tap(fn ($query) => TextSearch::apply($query, ['to', 'subject'], $search))
            ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($from !== null, fn ($query) => $query->where('sent_at', '>=', $from->startOfDay()))
            ->when($to !== null, fn ($query) => $query->where('sent_at', '<=', $to->endOfDay()))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Los tipos que hay en el registro, para el filtro.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return MailLog::query()->distinct()->orderBy('kind')->pluck('kind')->all();
    }
}
