<?php

namespace App\Modules\Core\Mail;

use App\Modules\Core\Actions\RecordMailConnectionUse;
use App\Modules\Core\Actions\RefreshMailToken;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Queries\MailConnectionQuery;
use App\Modules\Core\Support\QueueJobScope;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
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
 * sus intentos, aparece en «Correos y colas» (CF-S8).
 *
 * Límites de Exchange Online (unos 30 correos por minuto por buzón): lleva su propio ritmo, PACE_MAX correos por
 * PACE_WINDOW_SECONDS con el RateLimiter de la caché por omisión (Valkey en producción), así que cuenta entre todos los
 * workers; y ante un 429, o un 503 con Retry-After, espera lo que pide Microsoft (30 s si no lo dice) y reintenta hasta
 * THROTTLE_RETRIES veces. Esperar solo se hace dentro de un trabajo del worker de la cola (QueueJobScope) y, sumando el
 * ritmo y los Retry-After, nunca más de WAIT_BUDGET_SECONDS por correo, para caber en el tiempo máximo del worker
 * (60 s); lo que pida más lanza y la cola lo reintenta con su backoff. En una petición web (también con Octane) no espera:
 * falla en el acto. No guarda nada entre correos: lee la cuenta de la base y el contexto del contenedor en cada uno.
 */
final class MicrosoftGraphTransport extends AbstractTransport
{
    /** La llave del ritmo propio en el RateLimiter, compartida por todos los workers. */
    public const string PACE_KEY = 'core:mail:graph';

    /** 5 cada 15 s: en cualquier minuto salen a lo más 25, debajo de los 30 de Exchange Online, y la espera cabe en el tope. */
    private const int PACE_MAX = 5;

    private const int PACE_WINDOW_SECONDS = 15;

    private const int WAIT_BUDGET_SECONDS = 30;

    private const int THROTTLE_RETRIES = 2;

    private const int DEFAULT_RETRY_AFTER_SECONDS = 30;

    private const string BUSY = 'Microsoft está frenando el envío; intenta en un minuto.';

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

        if (($problems = $this->graph->configurationProblems()) !== []) {
            throw new TransportException(implode(' ', $problems));
        }

        $connection = $this->connections->current()
            ?? throw new TransportException('No hay una cuenta de envío conectada: conéctala en Configuración → Correos y colas.');

        if ($connection->expired_at !== null) {
            throw new TransportException("La conexión de la cuenta de envío {$connection->account_email} venció. Reconecta la cuenta de envío en Configuración → Correos y colas.");
        }

        // MailManager conserva el transporte entre trabajos: el contexto se pide cada vez, nunca en el constructor.
        $canWait = app(QueueJobScope::class)->inWorkerJob();
        $budget = self::WAIT_BUDGET_SECONDS;
        $payload = GraphMessage::payload($email);

        $this->pace($canWait, $budget);

        try {
            $this->deliver($this->refresh->handle($connection), $payload, $canWait, $budget);
        } catch (MicrosoftGraphError $error) {
            $this->record->handle($connection, $error->getMessage());

            throw new TransportException($error->getMessage(), 0, $error);
        }

        $this->record->handle($connection);
    }

    /**
     * Toma un lugar del ritmo propio. Sin lugar, en la cola espera lo que falte si cabe en lo que queda de espera; en la
     * web, o si no cabe, lanza sin llamar a Microsoft.
     */
    private function pace(bool $canWait, int &$budget): void
    {
        while (RateLimiter::tooManyAttempts(self::PACE_KEY, self::PACE_MAX)) {
            $seconds = max(1, RateLimiter::availableIn(self::PACE_KEY));

            if (! $canWait) {
                throw new TransportException('Nexus llegó al límite de correos por minuto de Microsoft; intenta en un minuto.');
            }

            if ($seconds > $budget) {
                throw new TransportException('Nexus llegó al límite de correos por minuto de Microsoft; la cola lo vuelve a intentar.');
            }

            Sleep::for($seconds)->seconds();
            $budget -= $seconds;
        }

        RateLimiter::hit(self::PACE_KEY, self::PACE_WINDOW_SECONDS);
    }

    /**
     * Manda el correo: con un 401 renueva el token una vez; con un freno de Microsoft espera y reintenta (solo en la
     * cola y dentro de lo que queda de espera).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws MicrosoftGraphError
     */
    private function deliver(MailConnection $connection, array $payload, bool $canWait, int &$budget): void
    {
        $renewed = false;
        $throttled = 0;

        while (true) {
            try {
                $this->graph->sendMail($connection->access_token, $payload);

                return;
            } catch (MicrosoftGraphError $error) {
                if ($error->status === 401 && ! $renewed) {
                    $connection = $this->refresh->handle($connection, rejectedToken: $connection->access_token);
                    $renewed = true;

                    continue;
                }

                if (! $error->throttled()) {
                    throw $error;
                }

                $seconds = max(1, $error->retryAfter ?? self::DEFAULT_RETRY_AFTER_SECONDS);
                $stop = match (true) {
                    ! $canWait => self::BUSY,
                    $throttled >= self::THROTTLE_RETRIES => "Microsoft siguió frenando el envío después de {$throttled} reintentos (HTTP {$error->status}); la cola lo vuelve a intentar.",
                    $seconds > $budget => "Microsoft pidió esperar {$seconds} s antes de mandar más correo (HTTP {$error->status}); la cola lo vuelve a intentar.",
                    default => null,
                };

                if ($stop !== null) {
                    throw new MicrosoftGraphError($stop, $error->status, $error->errorCode, $error->retryAfter);
                }

                Sleep::for($seconds)->seconds();
                $budget -= $seconds;
                $throttled++;
            }
        }
    }
}
