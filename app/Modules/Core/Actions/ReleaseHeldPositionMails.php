<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Queries\PositionMailboxQuery;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Manda los correos de puesto que esperaban un buzón y que ahora lo tienen (CE-02), del más viejo al más nuevo (su id es
 * UUIDv7, que sigue la hora): por cada uno en espera cuyo puesto
 * hoy resuelve a un buzón activo (PositionMailboxQuery), lo toma (lo marca liberado solo si nadie más lo tomó, así dos
 * corridas a la vez no lo mandan dos veces) y lo manda en ese momento, solo por `mail`, a quien iba
 * (`notifyNow($aviso, ['mail'])`): la dirección es la del buzón y pasa por las guardias de siempre (MN-05, CE-01). Si
 * falla, lo deja fallido (sin liberar) y lo reporta; no se reintenta solo. Da cuántos salieron.
 */
final class ReleaseHeldPositionMails
{
    public function __construct(private readonly PositionMailboxQuery $mailboxes) {}

    public function handle(): int
    {
        $released = 0;

        foreach (HeldPositionMail::query()->waiting()->with('user')->lazyById(100) as $held) {
            if ($this->mailboxes->mailboxFor($held->position()) === null || ! $this->claim($held)) {
                continue;
            }

            try {
                $notification = Crypt::decrypt($held->notification);

                if (! $notification instanceof Notification || $held->user === null) {
                    throw new RuntimeException("El correo en espera {$held->getKey()} no se pudo volver a armar.");
                }

                $held->user->notifyNow($notification, ['mail']);
                $released++;
            } catch (Throwable $exception) {
                HeldPositionMail::query()->whereKey($held->getKey())->update(['released_at' => null, 'failed_at' => now()]);
                report($exception);
            }
        }

        return $released;
    }

    private function claim(HeldPositionMail $held): bool
    {
        return HeldPositionMail::query()->whereKey($held->getKey())->waiting()->update(['released_at' => now()]) === 1;
    }
}
