<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Jobs\SendHeldPositionMail;
use App\Modules\Core\Models\HeldPositionMail;
use App\Modules\Core\Queries\PositionMailboxQuery;

/**
 * Libera los correos de puesto que esperaban un buzón y que ahora lo tienen (CE-02), del más viejo al más nuevo (su id es
 * UUIDv7, que sigue la hora). Por cada uno en espera cuyo puesto hoy resuelve a un buzón activo (PositionMailboxQuery), lo
 * toma (`released_at`, solo si nadie lo tomó antes: dos corridas a la vez no lo toman dos veces) y encola un
 * SendHeldPositionMail en la cola `bulk`, que lo manda y reintenta si Microsoft frena. Un aviso de la carga académica no se
 * toma mientras está oculta (MN-05): sigue esperando y sale en una corrida posterior. Da cuántos tomó.
 */
final class ReleaseHeldPositionMails
{
    public function __construct(
        private readonly PositionMailboxQuery $mailboxes,
        private readonly AcademicLoadVisibility $visibility,
    ) {}

    public function handle(): int
    {
        $hidden = $this->visibility->isHidden();
        $claimed = 0;

        foreach (HeldPositionMail::query()->waiting()->when($hidden, fn ($query) => $query->where('academic_load', false))->lazyById(100) as $held) {
            if ($this->mailboxes->mailboxFor($held->position()) === null || ! $this->claim($held)) {
                continue;
            }

            SendHeldPositionMail::dispatch($held->getKey());
            $claimed++;
        }

        return $claimed;
    }

    private function claim(HeldPositionMail $held): bool
    {
        return HeldPositionMail::query()->whereKey($held->getKey())->waiting()->update(['released_at' => now()]) === 1;
    }
}
