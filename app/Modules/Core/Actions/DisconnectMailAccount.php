<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\MailProvider;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Desconecta la cuenta de envío del correo (tarea 1.29, CF-S9): borra la fila con sus tokens, así que desde ese momento
 * el transporte `microsoft-graph` no manda nada hasta que se conecte otra. No revoca nada en Microsoft (eso se hace en
 * Microsoft Entra, quitando el consentimiento de la app). Deja bitácora con la cuenta, sin tokens, y devuelve la
 * cuenta desconectada, o null si no había ninguna.
 */
final class DisconnectMailAccount
{
    public function handle(User $actor): ?string
    {
        return DB::transaction(function () use ($actor): ?string {
            $connection = MailConnection::query()->where('provider', MailProvider::Microsoft)->lockForUpdate()->first();

            if ($connection === null) {
                return null;
            }

            $connection->delete();

            activity()
                ->performedOn($connection)
                ->causedBy($actor)
                ->event('mail-account-disconnected')
                ->withProperties(['account' => $connection->account_email])
                ->log("Desconectó la cuenta de envío del correo {$connection->account_email}");

            return $connection->account_email;
        });
    }
}
