<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Mail\MicrosoftGraph;
use App\Modules\Core\Mail\MicrosoftGraphError;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Support\RedactsSecrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Da la cuenta de envío con un access token que sirve (tarea 1.29, ADR-0032): si al suyo le quedan menos de 5 minutos,
 * o si Graph acaba de rechazar ese mismo token (`$rejectedToken`), lo renueva con el refresh token y guarda el refresh
 * token rotado. Relee la fila con `lockForUpdate()`, así que dos workers que mandan a la vez no renuevan los dos: el
 * segundo espera y encuentra el token nuevo. Si Microsoft ya no renueva la conexión (invalid_grant), la marca vencida
 * (`expired_at`, `last_error`) con bitácora sin tokens y lanza después de guardarlo; con otro error solo anota
 * `last_error` y lanza. La renovación de rutina no deja bitácora (sería una entrada por hora).
 *
 * @throws MicrosoftGraphError si la conexión ya no existe, venció o Microsoft no la renueva
 */
final class RefreshMailToken
{
    private const int MARGIN_MINUTES = 5;

    public function __construct(private readonly MicrosoftGraph $graph) {}

    public function handle(MailConnection $connection, #[SensitiveParameter] ?string $rejectedToken = null): MailConnection
    {
        $outcome = DB::transaction(function () use ($connection, $rejectedToken): MailConnection|MicrosoftGraphError {
            $fresh = MailConnection::query()->whereKey($connection->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return new MicrosoftGraphError('No hay una cuenta de envío conectada: conéctala en Configuración → Correos y colas.');
            }

            if ($fresh->expired_at !== null) {
                return self::expired($fresh);
            }

            $stillGood = $fresh->access_token_expires_at->isAfter(now()->addMinutes(self::MARGIN_MINUTES))
                && ($rejectedToken === null || ! hash_equals($fresh->access_token, $rejectedToken));

            if ($stillGood) {
                return $fresh;
            }

            try {
                $tokens = $this->graph->refresh($fresh->refresh_token);
            } catch (MicrosoftGraphError $error) {
                $this->fail($fresh, $error);

                return $error;
            }

            $fresh->update([
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_token_expires_at' => $tokens['expires_at'],
            ]);

            return $fresh;
        });

        if ($outcome instanceof MicrosoftGraphError) {
            throw $outcome;
        }

        return $outcome;
    }

    private function fail(MailConnection $connection, MicrosoftGraphError $error): void
    {
        $connection->update([
            'last_error' => Str::limit(RedactsSecrets::redact($error->getMessage()), 1000),
            ...($error->grantExpired() ? ['expired_at' => now()] : []),
        ]);

        if ($error->grantExpired()) {
            activity()
                ->performedOn($connection)
                ->event('mail-account-expired')
                ->withProperties(['account' => $connection->account_email, 'error' => $error->errorCode])
                ->log("La conexión de la cuenta de envío del correo {$connection->account_email} venció: hay que reconectarla");
        }
    }

    private static function expired(MailConnection $connection): MicrosoftGraphError
    {
        return new MicrosoftGraphError("La conexión de la cuenta de envío {$connection->account_email} venció. Reconecta la cuenta de envío en Configuración → Correos y colas.");
    }
}
