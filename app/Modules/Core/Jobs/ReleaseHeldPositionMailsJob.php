<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\Actions\ReleaseHeldPositionMails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Libera en la cola los correos de puesto que esperaban un buzón (ReleaseHeldPositionMails, CE-02). Lo despacha Core al
 * capturar o reactivar un buzón (PositionMailboxSaved). Cada correo se toma una sola vez, así que correr dos a la vez o
 * de nuevo no repite nada; un correo que falla queda fallido, por eso el trabajo no se reintenta.
 */
final class ReleaseHeldPositionMailsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(ReleaseHeldPositionMails $release): void
    {
        $release->handle();
    }
}
