<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Jobs\ReleaseHeldPositionMailsJob;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «Reintentar» un correo de puesto que falló (CE-02): vuelve a la espera con los intentos en cero y, después del commit,
 * se encola una liberación, que lo manda si su puesto ya tiene buzón. Uno que no falló, o descartado, no cambia. Deja la
 * bitácora. Quién puede lo decide HeldPositionMailPolicy (solo el admin).
 */
final class RetryHeldPositionMail
{
    public function handle(HeldPositionMail $held, ?User $actor = null): HeldPositionMail
    {
        return DB::transaction(function () use ($held, $actor): HeldPositionMail {
            $held->setRawAttributes(HeldPositionMail::query()->lockForUpdate()->findOrFail($held->getKey())->getAttributes(), sync: true);

            if ($held->failed_at === null || $held->discarded_at !== null) {
                return $held;
            }

            $held->update(['failed_at' => null, 'released_at' => null, 'attempts' => 0]);

            activity()
                ->performedOn($held)
                ->causedBy($actor)
                ->event('position-mail-retried')
                ->withProperties(['kind' => $held->kind, 'subject' => $held->subject, 'role' => $held->role->value])
                ->log("Reintentó el correo de puesto «{$held->subject}»");

            DB::afterCommit(fn (): mixed => ReleaseHeldPositionMailsJob::dispatch());

            return $held;
        });
    }
}
