<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «Descartar» un correo de puesto que espera o que falló (CE-02): ya no se manda, pero no se borra (`discarded_at`). Uno
 * que ya se tomó para mandarse o ya descartado no cambia. La campana de quien lo recibió no se toca. Deja la bitácora.
 * Quién puede lo decide HeldPositionMailPolicy (solo el admin).
 */
final class DiscardHeldPositionMail
{
    public function handle(HeldPositionMail $held, ?User $actor = null): HeldPositionMail
    {
        return DB::transaction(function () use ($held, $actor): HeldPositionMail {
            $held->setRawAttributes(HeldPositionMail::query()->lockForUpdate()->findOrFail($held->getKey())->getAttributes(), sync: true);

            if ($held->released_at !== null || $held->discarded_at !== null) {
                return $held;
            }

            $held->update(['discarded_at' => now()]);

            activity()
                ->performedOn($held)
                ->causedBy($actor)
                ->event('position-mail-discarded')
                ->withProperties(['kind' => $held->kind, 'subject' => $held->subject, 'role' => $held->role->value, 'failed' => $held->failed_at !== null])
                ->log("Descartó el correo de puesto «{$held->subject}»");

            return $held;
        });
    }
}
