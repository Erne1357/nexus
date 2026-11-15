<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Activa o desactiva un atajo del botón de atajos (CF-S6). Desactivado no sale en la pantalla de acceso (LG-02). Si no cambia el
 * estado, no escribe ni registra.
 */
final class SetLoginShortcutActive
{
    public function handle(LoginShortcut $notice, bool $active): LoginShortcut
    {
        return DB::transaction(function () use ($notice, $active): LoginShortcut {
            $notice = LoginShortcut::query()->lockForUpdate()->findOrFail($notice->getKey());

            if ($notice->is_active === $active) {
                return $notice;
            }

            $notice->update(['is_active' => $active]);

            activity()
                ->performedOn($notice)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['is_active' => $active], 'old' => ['is_active' => ! $active]])
                ->log(($active ? 'Activó' : 'Desactivó')." el atajo «{$notice->title}»");

            return $notice;
        });
    }
}
