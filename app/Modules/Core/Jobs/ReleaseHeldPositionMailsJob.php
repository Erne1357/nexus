<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Actions\ReleaseHeldPositionMails;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Libera en la cola los correos de puesto que esperaban un buzón (ReleaseHeldPositionMails, CE-02). Lo despacha Core al
 * capturar o reactivar un buzón (PositionMailboxSaved), al reintentar uno fallido, y el scheduler cada 15 minutos (para
 * lo que esperaba por la carga oculta, MN-05, o por un reintento). Uno a la vez: único mientras espera en la cola y sin
 * traslaparse al correr (el que llegue mientras otro corre se descarta: el siguiente barrido lo cubre, y cada correo se
 * toma una sola vez). Solo toma y encola; no manda.
 */
final class ReleaseHeldPositionMailsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function handle(ReleaseHeldPositionMails $release): void
    {
        $release->handle();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('release-held-position-mails'))->dontRelease()->expireAfter(600)];
    }
}
