<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\LoginNotice;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Borra un aviso (CF-S6): borrado lógico, la foto se conserva en el disco y queda en la bitácora. Un borrado ya no sale
 * en la pantalla de acceso ni en el panel.
 */
final class DeleteLoginNotice
{
    public function handle(LoginNotice $notice): void
    {
        DB::transaction(function () use ($notice): void {
            $notice = LoginNotice::query()->lockForUpdate()->findOrFail($notice->getKey());
            $notice->delete();

            activity()
                ->performedOn($notice)
                ->event(ActivityEvent::Deleted)
                ->withChanges(['old' => $notice->only(['title', 'image_path', 'link_url', 'is_active'])])
                ->log("Borró el aviso «{$notice->title}»");
        });
    }
}
