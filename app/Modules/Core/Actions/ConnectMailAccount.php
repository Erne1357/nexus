<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Enums\MailProvider;
use App\Modules\Core\Mail\MicrosoftGraph;
use App\Modules\Core\Mail\MicrosoftGraphError;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Conecta la cuenta de envío del correo (tarea 1.29, ADR-0032, CF-S9) con el código que Microsoft devolvió al regreso
 * del inicio de sesión: lo cambia por los tokens (con el verificador de PKCE), lee la cuenta con `GET /me` y la guarda
 * con los tokens cifrados. Las llamadas a Microsoft van antes de la transacción, para no tener la fila bloqueada
 * mientras contesta. Siempre queda una sola conexión: si ya había una, la reemplaza (y se borra lo que marcaba vencido
 * o fallido). La bitácora dice la cuenta y la anterior, nunca un token. Quién puede lo decide MailConnectionPolicy.
 *
 * @throws MicrosoftGraphError si Microsoft no da los tokens, los permisos o la cuenta
 */
final class ConnectMailAccount
{
    public function __construct(private readonly MicrosoftGraph $graph) {}

    public function handle(User $actor, #[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): MailConnection
    {
        $tokens = $this->graph->redeemCode($code, $verifier);
        $account = $this->graph->accountEmail($tokens['access_token']);

        return DB::transaction(function () use ($actor, $tokens, $account): MailConnection {
            $connection = MailConnection::query()->where('provider', MailProvider::Microsoft)->lockForUpdate()->first() ?? new MailConnection(['provider' => MailProvider::Microsoft]);
            $previous = $connection->exists ? $connection->account_email : null;

            $connection->fill([
                'account_email' => $account,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_token_expires_at' => $tokens['expires_at'],
                'connected_by' => $actor->getKey(),
                'connected_at' => now(),
                'last_used_at' => null,
                'last_error' => null,
                'expired_at' => null,
            ])->save();

            activity()
                ->performedOn($connection)
                ->causedBy($actor)
                ->event('mail-account-connected')
                ->withProperties(['account' => $account, 'previous' => $previous])
                ->log("Conectó la cuenta de envío del correo {$account}");

            return $connection;
        });
    }
}
