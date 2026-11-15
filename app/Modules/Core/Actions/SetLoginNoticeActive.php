<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\LoginNotice;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Activa o desactiva un aviso del carrusel (CF-S6). Desactivado no sale en la pantalla de acceso (LG-01). Si no cambia el
 * estado, no escribe ni registra.
 */
final class SetLoginNoticeActive
{
    public function handle(LoginNotice $notice, bool $active): LoginNotice
    {
        return DB::transaction(function () use ($notice, $active): LoginNotice {
            $notice = LoginNotice::query()->lockForUpdate()->findOrFail($notice->getKey());

            if ($notice->is_active === $active) {
                return $notice;
            }

            $notice->update(['is_active' => $active]);

            activity()
                ->performedOn($notice)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['is_active' => $active], 'old' => ['is_active' => ! $active]])
                ->log(($active ? 'Activó' : 'Desactivó')." el aviso «{$notice->title}»");

            return $notice;
        });
    }
}
