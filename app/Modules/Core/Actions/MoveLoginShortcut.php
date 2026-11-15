<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Sube o baja un atajo un lugar en el orden de los atajos (CF-S6, LG-02). Renumera todos los atajos en su orden actual
 * (los que empatan en `sort_order` quedan por antigüedad) y cambia de lugar al atajo con su vecino; en el primero no sube
 * ni en el último baja.
 */
final class MoveLoginShortcut
{
    public function handle(LoginShortcut $notice, int $direction): void
    {
        DB::transaction(function () use ($notice, $direction): void {
            $ordered = LoginShortcut::query()->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get()->values();
            $from = $ordered->search(fn (LoginShortcut $item): bool => $item->is($notice));
            $to = $from === false ? false : $from + ($direction < 0 ? -1 : 1);

            if ($from === false || $to < 0 || $to >= $ordered->count()) {
                return;
            }

            $ids = $ordered->pluck('id')->all();
            [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];

            foreach ($ids as $position => $id) {
                LoginShortcut::query()->whereKey($id)->where('sort_order', '!=', $position)->update(['sort_order' => $position]);
            }

            activity()
                ->performedOn($notice)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['sort_order' => $to], 'old' => ['sort_order' => $from]])
                ->log(($direction < 0 ? 'Subió' : 'Bajó')." el atajo «{$notice->title}» al lugar ".($to + 1));
        });
    }
}
