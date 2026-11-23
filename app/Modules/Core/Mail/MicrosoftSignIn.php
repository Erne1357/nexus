<?php

namespace App\Modules\Core\Mail;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Las dos mitades de la conexión de la cuenta de envío en la sesión de quien la inicia (tarea 1.29, ADR-0032):
 * «Conectar con Microsoft» guarda un `state` y el verificador de PKCE y da la página de Microsoft; el regreso los
 * saca de la sesión (una sola vez) y solo sirven si el `state` coincide, si los guardó el mismo usuario y si no pasaron
 * más de 10 minutos. El aviso del resultado va en la sesión flash, para la tarjeta de «Correos y colas». No guarda nada
 * en la instancia.
 */
final readonly class MicrosoftSignIn
{
    public const string SESSION_KEY = 'core.mail-connection.sign-in';

    public const string NOTICE_KEY = 'core.mail-connection.notice';

    private const int MAX_AGE_SECONDS = 600;

    public function __construct(private MicrosoftGraph $graph) {}

    /**
     * Guarda lo de esta conexión en la sesión (reemplaza una anterior sin terminar) y da la página de Microsoft.
     */
    public function begin(Session $session, string $userId): string
    {
        $state = Str::random(40);
        $verifier = Str::random(96);

        $session->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier, 'user' => $userId, 'at' => now()->getTimestamp()]);

        return $this->graph->authorizationUrl($state, MicrosoftGraph::codeChallenge($verifier));
    }

    /**
     * El verificador de PKCE de la conexión que el mismo usuario inició con ese `state`, o null si el regreso no
     * corresponde a una. Siempre la quita de la sesión.
     */
    public function finish(Session $session, mixed $state, string $userId): ?string
    {
        $pending = $session->pull(self::SESSION_KEY);

        $valid = is_array($pending)
            && is_string($state) && $state !== ''
            && is_string($pending['state'] ?? null) && hash_equals($pending['state'], $state)
            && ($pending['user'] ?? null) === $userId
            && is_int($pending['at'] ?? null) && now()->getTimestamp() - $pending['at'] <= self::MAX_AGE_SECONDS
            && is_string($pending['verifier'] ?? null);

        return $valid ? $pending['verifier'] : null;
    }

    /**
     * El aviso que la tarjeta muestra después del regreso.
     */
    public static function notify(Session $session, string $tone, string $title, string $text): void
    {
        $session->flash(self::NOTICE_KEY, ['tone' => $tone, 'title' => $title, 'text' => $text]);
    }
}
