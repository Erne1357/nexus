<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\PositionMailboxDeactivated;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Desactiva un buzón de puesto (CE-02): nunca se borra. Los avisos nuevos de su puesto vuelven a esperar un buzón, salvo
 * que otro activo los cubra; los que ya salieron no cambian. Relee con bloqueo; si ya estaba inactivo no hace nada. Deja
 * la bitácora y, después del commit, PositionMailboxDeactivated. Quién puede lo decide PositionMailboxPolicy.
 */
final class DeactivatePositionMailbox
{
    public function handle(PositionMailbox $mailbox, ?User $actor = null): PositionMailbox
    {
        return DB::transaction(function () use ($mailbox, $actor): PositionMailbox {
            $mailbox->setRawAttributes(PositionMailbox::query()->lockForUpdate()->findOrFail($mailbox->getKey())->getAttributes(), sync: true);

            if (! $mailbox->is_active) {
                return $mailbox;
            }

            $mailbox->update(['is_active' => false]);

            activity()
                ->performedOn($mailbox)
                ->causedBy($actor)
                ->event(ActivityEvent::Updated)
                ->withChanges(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]])
                ->log("Desactivó el buzón {$mailbox->email} del puesto {$mailbox->role->label()}");

            PositionMailboxDeactivated::dispatch($mailbox);

            return $mailbox;
        });
    }
}
