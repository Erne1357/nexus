<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\LoginShortcut;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Borra un atajo (CF-S6): borrado lógico, el archivo se conserva en el disco y queda en la bitácora. Un borrado ya no sale
 * en la pantalla de acceso ni en el panel.
 */
final class DeleteLoginShortcut
{
    public function handle(LoginShortcut $notice): void
    {
        DB::transaction(function () use ($notice): void {
            $notice = LoginShortcut::query()->lockForUpdate()->findOrFail($notice->getKey());
            $notice->delete();

            activity()
                ->performedOn($notice)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $notice->only(['title', 'kind', 'url', 'file_path', 'is_active'])])
                ->log("Borró el atajo «{$notice->title}»");
        });
    }
}
