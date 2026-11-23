<?php

namespace App\Modules\Core\Mail;

use App\Modules\Core\Actions\RecordMailConnectionUse;
use App\Modules\Core\Actions\RefreshMailToken;
use App\Modules\Core\Queries\MailConnectionQuery;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * El transporte `microsoft-graph` de Laravel (tarea 1.29, ADR-0032; `Mail::extend` en CoreServiceProvider): manda cada
 * correo con `POST /me/sendMail` de Microsoft Graph como la cuenta de envío conectada en «Correos y colas», con permisos
 * delegados. El remitente es siempre esa cuenta, aunque MAIL_FROM_ADDRESS diga otra (la tarjeta lo avisa). Antes de
 * mandar, RefreshMailToken renueva el token si vence pronto; si Graph rechaza un token vigente (401), lo renueva una vez
 * y vuelve a mandar. Cada resultado queda en la cuenta (RecordMailConnectionUse). Todo error sale como
 * TransportException con el mensaje de Microsoft y sin tokens: el trabajo de la cola falla, se reintenta y, al agotar
 * sus intentos, aparece en «Correos y colas» (CF-S8). No guarda nada entre correos: lee la cuenta de la base en cada
 * uno, así que sirve igual en el worker de la cola y con Octane.
 */
final class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(
        private readonly MicrosoftGraph $graph,
        private readonly MailConnectionQuery $connections,
        private readonly RefreshMailToken $refresh,
        private readonly RecordMailConnectionUse $record,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'microsoft-graph';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new TransportException('El transporte microsoft-graph solo manda correos armados por Laravel (Email de Symfony).');
        }

        if (($missing = $this->graph->missingVariables()) !== []) {
            throw new TransportException('Faltan variables de la app de Azure en el .env: '.implode(', ', $missing).'.');
        }

        $connection = $this->connections->current()
            ?? throw new TransportException('No hay una cuenta de envío conectada: conéctala en Configuración → Correos y colas.');

        if ($connection->expired_at !== null) {
            throw new TransportException("La conexión de la cuenta de envío {$connection->account_email} venció. Reconecta la cuenta de envío en Configuración → Correos y colas.");
        }

        $payload = GraphMessage::payload($email);

        try {
            $connection = $this->refresh->handle($connection);

            try {
                $this->graph->sendMail($connection->access_token, $payload);
            } catch (MicrosoftGraphError $error) {
                if ($error->status !== 401) {
                    throw $error;
                }

                $connection = $this->refresh->handle($connection, rejectedToken: $connection->access_token);
                $this->graph->sendMail($connection->access_token, $payload);
            }
        } catch (MicrosoftGraphError $error) {
            $this->record->handle($connection, $error->getMessage());

            throw new TransportException($error->getMessage(), 0, $error);
        }

        $this->record->handle($connection);
    }
}
