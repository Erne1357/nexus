<?php

namespace App\Modules\Core\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Lo que Nexus le pide a Microsoft para mandar el correo con permisos delegados (tarea 1.29, ADR-0032), sin SDK: el
 * inicio de sesión de la cuenta de envío (Authorization Code con PKCE), cambiar el código o el refresh token por un
 * access token en el endpoint de tokens de Microsoft Entra, leer la cuenta (`GET /me`) y mandar un correo
 * (`POST /me/sendMail`). La app de Azure sale de `config('services.microsoft_graph')` en cada llamada y la clase no
 * guarda nada entre llamadas, así que es segura con Octane y en el worker de la cola. Todo error es un
 * MicrosoftGraphError con su mensaje en español y sin tokens.
 */
final class MicrosoftGraph
{
    /** Los permisos delegados que pide: renovar sin la persona, mandar correo y leer su perfil (la cuenta). */
    public const string SCOPES = 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read';

    public const string GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    private const string LOGIN_URL = 'https://login.microsoftonline.com';

    /** @var array<string, string> la variable del .env de cada valor de `services.microsoft_graph` */
    private const array VARIABLES = [
        'tenant_id' => 'MICROSOFT_GRAPH_TENANT_ID',
        'client_id' => 'MICROSOFT_GRAPH_CLIENT_ID',
        'client_secret' => 'MICROSOFT_GRAPH_CLIENT_SECRET',
    ];

    /**
     * Las variables del .env que faltan para hablar con Microsoft, en el orden de la guía.
     *
     * @return list<string>
     */
    public function missingVariables(): array
    {
        return array_values(array_filter(
            self::VARIABLES,
            fn (string $variable, string $key): bool => trim((string) config("services.microsoft_graph.{$key}")) === '',
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function configured(): bool
    {
        return $this->missingVariables() === [];
    }

    /**
     * La dirección de regreso que se registra en Azure como Redirect URI (Web): absoluta con APP_URL y no con el host de
     * la petición, para que sea siempre la registrada aunque se entre por otro nombre o por un túnel.
     */
    public function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('core.mail-connection.callback', absolute: false);
    }

    /**
     * La página de inicio de sesión de Microsoft para conectar la cuenta de envío. `prompt=select_account` deja elegir
     * el buzón que será remitente aunque el navegador ya tenga abierta otra cuenta.
     */
    public function authorizationUrl(string $state, string $codeChallenge): string
    {
        return $this->authority().'/authorize?'.http_build_query([
            'client_id' => $this->value('client_id'),
            'response_type' => 'code',
            'response_mode' => 'query',
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * El reto S256 de PKCE para un verificador.
     */
    public static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Cambia el código del regreso por los tokens. Exige que Microsoft haya dado `Mail.Send` y un refresh token
     * (`offline_access`): sin ellos la conexión no serviría.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: CarbonImmutable}
     *
     * @throws MicrosoftGraphError
     */
    public function redeemCode(#[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): array
    {
        $response = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $this->redirectUri(),
        ]);

        if ($response->failed()) {
            throw MicrosoftGraphError::fromTokenResponse($response, refreshing: false);
        }

        $scope = $response->json('scope');

        if (is_string($scope) && stripos($scope, 'Mail.Send') === false) {
            throw new MicrosoftGraphError('Microsoft no dio el permiso Mail.Send: agrégalo en API permissions de la app de Azure (Microsoft Graph, Delegated) y vuelve a conectar.');
        }

        if (! is_string($response->json('refresh_token')) || $response->json('refresh_token') === '') {
            throw new MicrosoftGraphError('Microsoft no dio el permiso offline_access, sin el cual la conexión vence en una hora: agrégalo en API permissions de la app de Azure y vuelve a conectar.');
        }

        return $this->tokens($response, null);
    }

    /**
     * Renueva el access token con el refresh token. Si Microsoft no manda otro refresh token, sigue valiendo el que se
     * usó.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: CarbonImmutable}
     *
     * @throws MicrosoftGraphError
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): array
    {
        $response = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);

        if ($response->failed()) {
            throw MicrosoftGraphError::fromTokenResponse($response, refreshing: true);
        }

        return $this->tokens($response, $refreshToken);
    }

    /**
     * El correo de la cuenta conectada: `mail` o, si el buzón no lo tiene, `userPrincipalName`, en minúsculas.
     *
     * @throws MicrosoftGraphError
     */
    public function accountEmail(#[SensitiveParameter] string $accessToken): string
    {
        $response = $this->send(fn (): Response => $this->graph($accessToken, 15)->get(self::GRAPH_URL.'/me', ['$select' => 'mail,userPrincipalName']), 'graph.microsoft.com');

        if ($response->failed()) {
            throw MicrosoftGraphError::fromGraphResponse($response, 'Microsoft Graph no dio la cuenta conectada');
        }

        foreach (['mail', 'userPrincipalName'] as $field) {
            $value = $response->json($field);

            if (is_string($value) && str_contains($value, '@')) {
                return mb_strtolower(trim($value));
            }
        }

        throw new MicrosoftGraphError('Microsoft Graph no dio el correo de la cuenta conectada.');
    }

    /**
     * Manda un correo como la cuenta del token (`POST /me/sendMail`, que responde 202 sin cuerpo).
     *
     * @param  array<string, mixed>  $payload  lo que arma GraphMessage
     *
     * @throws MicrosoftGraphError
     */
    public function sendMail(#[SensitiveParameter] string $accessToken, array $payload): void
    {
        $response = $this->send(fn (): Response => $this->graph($accessToken, 30)->post(self::GRAPH_URL.'/me/sendMail', $payload), 'graph.microsoft.com');

        if ($response->failed()) {
            throw MicrosoftGraphError::fromGraphResponse($response, 'Microsoft Graph no aceptó el correo');
        }
    }

    /**
     * @param  array<string, string>  $grant
     */
    private function token(#[SensitiveParameter] array $grant): Response
    {
        return $this->send(fn (): Response => Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->post($this->authority().'/token', [
            'client_id' => $this->value('client_id'),
            'client_secret' => $this->value('client_secret'),
            'scope' => self::SCOPES,
            ...$grant,
        ]), 'login.microsoftonline.com');
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: CarbonImmutable}
     */
    private function tokens(Response $response, #[SensitiveParameter] ?string $previousRefreshToken): array
    {
        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new MicrosoftGraphError('Microsoft no entregó el access token.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : (string) $previousRefreshToken,
            'expires_at' => CarbonImmutable::now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
        ];
    }

    private function graph(#[SensitiveParameter] string $accessToken, int $timeoutSeconds): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->connectTimeout(5)->timeout($timeoutSeconds);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request, string $host): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw MicrosoftGraphError::unreachable($host);
        }
    }

    private function authority(): string
    {
        return self::LOGIN_URL.'/'.rawurlencode($this->value('tenant_id')).'/oauth2/v2.0';
    }

    private function value(string $key): string
    {
        return trim((string) config("services.microsoft_graph.{$key}"));
    }
}
