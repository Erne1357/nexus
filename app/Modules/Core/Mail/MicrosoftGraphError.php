<?php

namespace App\Modules\Core\Mail;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lo que Microsoft rechazó o no contestó (tarea 1.29, ADR-0032), con un mensaje en español que se puede guardar y
 * mostrar: el código de Microsoft y la primera línea de su descripción, nunca un token ni el secreto de la app (no
 * guarda la respuesta ni la petición). `status` es el HTTP (0 si no hubo respuesta) y `code`, el de Microsoft
 * (`invalid_grant`, `ErrorInvalidRecipients`).
 */
final class MicrosoftGraphError extends RuntimeException
{
    /** Los códigos del endpoint de tokens con los que Microsoft ya no renueva la conexión: hay que volver a conectar. */
    private const array EXPIRED_GRANTS = ['invalid_grant', 'interaction_required', 'consent_required'];

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = '',
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(Str::limit($message, 900));
    }

    /**
     * Un error del endpoint de tokens (`{"error": "invalid_grant", "error_description": "AADSTS…"}`), al renovar
     * (`$refreshing`) o al cambiar el código por los tokens.
     */
    public static function fromTokenResponse(Response $response, bool $refreshing): self
    {
        $code = is_string($response->json('error')) ? $response->json('error') : 'http_'.$response->status();
        $detail = self::firstLine(is_string($response->json('error_description')) ? $response->json('error_description') : '');
        $why = trim("({$code}) {$detail}");

        $message = match (true) {
            $refreshing && in_array($code, self::EXPIRED_GRANTS, true) => "La conexión con Microsoft venció o se revocó {$why}. Reconecta la cuenta de envío en Configuración → Correos y colas.",
            $code === 'invalid_client' || $code === 'unauthorized_client' => "Microsoft rechazó la app de Azure {$why}. Revisa MICROSOFT_GRAPH_CLIENT_ID y MICROSOFT_GRAPH_CLIENT_SECRET en el .env: el secreto pudo vencer.",
            $refreshing => "Microsoft no renovó la conexión de la cuenta de envío {$why}.",
            default => "Microsoft no entregó los permisos de la cuenta {$why}.",
        };

        return new self($message, $response->status(), $code);
    }

    /**
     * Un error de Microsoft Graph (`{"error": {"code": "…", "message": "…"}}`).
     */
    public static function fromGraphResponse(Response $response, string $doing): self
    {
        $code = is_string($response->json('error.code')) ? $response->json('error.code') : '';
        $detail = self::firstLine(is_string($response->json('error.message')) ? $response->json('error.message') : '');

        $retryAfter = trim($response->header('Retry-After'));

        return new self(
            trim("{$doing} (HTTP {$response->status()}".($code === '' ? '' : ", {$code}")."): {$detail}", ' :'),
            $response->status(),
            $code,
            ctype_digit($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function unreachable(string $host): self
    {
        return new self("No se pudo conectar con Microsoft ({$host}); el correo se vuelve a intentar.");
    }

    /**
     * Si Microsoft está frenando el envío (límites de Exchange Online): un 429, o un 503 que dice cuándo volver
     * (Retry-After).
     */
    public function throttled(): bool
    {
        return $this->status === 429 || ($this->status === 503 && $this->retryAfter !== null);
    }

    /**
     * Si Microsoft ya no renueva la conexión y hay que volver a conectar la cuenta.
     */
    public function grantExpired(): bool
    {
        return in_array($this->errorCode, self::EXPIRED_GRANTS, true);
    }

    private static function firstLine(string $text): string
    {
        return Str::limit(trim((string) strtok(trim($text), "\r\n")), 300);
    }
}
