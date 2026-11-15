<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\LoginNotice;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Sube o baja un aviso un lugar en el orden del carrusel (CF-S6, LG-01). Renumera todos los avisos en su orden actual
 * (los que empatan en `sort_order` quedan por antigüedad) y cambia de lugar al aviso con su vecino; en el primero no sube
 * ni en el último baja.
 */
final class MoveLoginNotice
{
    public function handle(LoginNotice $notice, int $direction): void
    {
        DB::transaction(function () use ($notice, $direction): void {
            $ordered = LoginNotice::query()->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get()->values();
            $from = $ordered->search(fn (LoginNotice $item): bool => $item->is($notice));
            $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

            if ($from === false || $to < 0 || $to >= $ordered->count()) {
                return;
            }

            $ids = $ordered->pluck('id')->all();
            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $position => $id) {
                LoginNotice::query()->whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
            }

            activity()
                ->performedOn($notice)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['sort_order' => $to], 'old' => ['sort_order' => $from]])
                ->log(($direction < 0 ? 'Subió' : 'Bajó')." el aviso «{$notice->title}» al lugar ".($to + 1));
        });
    }
}
