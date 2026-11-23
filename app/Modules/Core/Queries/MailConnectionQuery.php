<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\MailConnectionState;
use App\Modules\Core\Enums\MailProvider;
use App\Modules\Core\Mail\MicrosoftGraph;
use App\Modules\Core\Models\MailConnection;

/**
 * La cuenta de envío del correo (tarea 1.29, CF-S9): la conexión vigente, para el transporte, y lo que pinta su tarjeta
 * en «Correos y colas».
 */
final readonly class MailConnectionQuery
{
    public function __construct(private MicrosoftGraph $graph) {}

    public function current(): ?MailConnection
    {
        return MailConnection::query()->where('provider', MailProvider::Microsoft)->first();
    }

    /**
     * El estado de la tarjeta: faltan variables (cuáles), sin conectar, conectada o vencida; la conexión con quien la
     * conectó; la dirección de regreso para Azure; si el correo de Nexus ya sale por este transporte (MAIL_MAILER) y el
     * MAIL_FROM_ADDRESS que no es la cuenta conectada, si lo hay.
     *
     * @return array{state: MailConnectionState, missing: list<string>, connection: ?MailConnection, redirectUri: string, mailer: string, active: bool, fromMismatch: ?string}
     */
    public function card(): array
    {
        $missing = $this->graph->missingVariables();
        $connection = MailConnection::query()->with('connectedBy:id,name,username')->where('provider', MailProvider::Microsoft)->first();
        $from = mb_strtolower(trim((string) config('mail.from.address')));
        $mailer = (string) config('mail.default');

        return [
            'state' => match (true) {
                $missing !== [] => MailConnectionState::Unconfigured,
                $connection === null => MailConnectionState::Disconnected,
                $connection->expired_at !== null => MailConnectionState::Expired,
                default => MailConnectionState::Connected,
            },
            'missing' => $missing,
            'connection' => $connection,
            'redirectUri' => $this->graph->redirectUri(),
            'mailer' => $mailer,
            'active' => $mailer === 'microsoft-graph',
            'fromMismatch' => $connection !== null && $from !== '' && $from !== $connection->account_email ? $from : null,
        ];
    }
}
