<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\TermCalendarChanged;
use App\Modules\Core\Queries\TermCalendarQuery;
use Illuminate\Support\Facades\Cache;

/**
 * Borra de la caché el calendario del periodo que cambió: una sola entrada por periodo, con la ventana general
 * y las de todos los campus (TermCalendarQuery::key).
 */
final class ForgetTermCalendar
{
    public function handle(TermCalendarChanged $event): void
    {
        Cache::forget(TermCalendarQuery::key($event->term->getKey()));
    }
}
