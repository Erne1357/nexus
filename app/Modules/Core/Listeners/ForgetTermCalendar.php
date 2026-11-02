<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Support\Facades\Cache;

/**
 * Borra de la caché el calendario del periodo que cambió. Una ventana general afecta a todos los
 * campus, así que siempre se borran la clave `all` y la de cada campus (también inactivos y borrados);
 * es simple y correcto, y son pocos campus.
 */
final class ForgetTermCalendar
{
    public function handle(TermCalendarChanged $event): void
    {
        $termId = $event->term->getKey();

        Cache::forget(TermCalendarQuery::key($termId, null));

        Campus::withTrashed()->pluck('id')->each(
            fn (string $campusId): bool => Cache::forget(TermCalendarQuery::key($termId, $campusId)),
        );
    }
}
