<?php

use App\Modules\Core\Models\MailConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/*
| Tarea 1.29 (ADR-0032): el transporte `microsoft-graph` manda cada correo con `POST /me/sendMail` de Microsoft Graph,
| como la cuenta conectada en «Correos y colas». Antes de mandar renueva el token si le quedan menos de 5 minutos y
| guarda el refresh token rotado; si Microsoft dice que la conexión venció la marca vencida y lanza. Todo error es una
| TransportException con el mensaje de Microsoft, sin tokens, para que el trabajo de la cola falle y se reintente.
*/

const GRAPH_SEND = 'https://graph.microsoft.com/v1.0/me/sendMail';
const GRAPH_TOKEN = 'https://login.microsoftonline.com/tenant-prueba/oauth2/v2.0/token';

beforeEach(function () {
    config(['services.microsoft_graph' => ['tenant_id' => 'tenant-prueba', 'client_id' => 'cliente-prueba', 'client_secret' => 'secreto-prueba']]);
    Http::preventStrayRequests();
});

function graphEmail(): Email
{
    return (new Email)
        ->from('otra@cdjuarez.tecnm.mx')
        ->to('ana@cdjuarez.tecnm.mx')
        ->cc('beto@cdjuarez.tecnm.mx')
        ->subject('Tu acceso a Nexus')
        ->text('Texto')
        ->html('<p>Hola</p>');
}

function sendThroughGraph(?Email $email = null): void
{
    Mail::mailer('microsoft-graph')->getSymfonyTransport()->send($email ?? graphEmail());
}

it('manda el correo como la cuenta conectada, con el JSON de Graph y sin remitente propio', function () {
    $connection = MailConnection::factory()->create(['access_token' => 'at-vigente', 'access_token_expires_at' => now()->addHour()]);
    Http::fake([GRAPH_SEND => Http::response(null, 202)]);

    sendThroughGraph();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === GRAPH_SEND
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer at-vigente')
        && $request['saveToSentItems'] === true
        && $request['message']['subject'] === 'Tu acceso a Nexus'
        && $request['message']['body'] === ['contentType' => 'HTML', 'content' => '<p>Hola</p>']
        && $request['message']['toRecipients'] === [['emailAddress' => ['address' => 'ana@cdjuarez.tecnm.mx']]]
        && $request['message']['ccRecipients'] === [['emailAddress' => ['address' => 'beto@cdjuarez.tecnm.mx']]]
        && ! array_key_exists('from', $request['message']));

    $fresh = $connection->fresh();

    expect($fresh->last_used_at)->not->toBeNull()
        ->and($fresh->last_error)->toBeNull();
});

it('renueva el token que vence en menos de 5 minutos, guarda el refresh token rotado y manda con el nuevo', function () {
    $connection = MailConnection::factory()->create([
        'access_token' => 'at-viejo',
        'refresh_token' => 'rt-viejo',
        'access_token_expires_at' => now()->addMinutes(4),
    ]);

    Http::fake([
        GRAPH_TOKEN => Http::response(['token_type' => 'Bearer', 'access_token' => 'at-nuevo', 'refresh_token' => 'rt-nuevo', 'expires_in' => 3600]),
        GRAPH_SEND => Http::response(null, 202),
    ]);

    sendThroughGraph();

    Http::assertSent(fn (Request $request): bool => $request->url() === GRAPH_TOKEN
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'rt-viejo'
        && $request['client_id'] === 'cliente-prueba'
        && $request['client_secret'] === 'secreto-prueba'
        && str_contains($request['scope'], 'https://graph.microsoft.com/Mail.Send'));
    Http::assertSent(fn (Request $request): bool => $request->url() === GRAPH_SEND && $request->hasHeader('Authorization', 'Bearer at-nuevo'));

    $fresh = $connection->fresh();
    $raw = DB::table('mail_connections')->where('id', $connection->id)->first();

    expect($fresh->access_token)->toBe('at-nuevo')
        ->and($fresh->refresh_token)->toBe('rt-nuevo')
        ->and($fresh->access_token_expires_at->isAfter(now()->addMinutes(55)))->toBeTrue()
        ->and($raw->access_token)->not->toContain('at-nuevo')
        ->and($raw->refresh_token)->not->toContain('rt-nuevo');
});

it('conserva el refresh token si Microsoft no manda otro al renovar', function () {
    $connection = MailConnection::factory()->create(['refresh_token' => 'rt-unico', 'access_token_expires_at' => now()->subMinute()]);

    Http::fake([
        GRAPH_TOKEN => Http::response(['access_token' => 'at-nuevo', 'expires_in' => 3600]),
        GRAPH_SEND => Http::response(null, 202),
    ]);

    sendThroughGraph();

    expect($connection->fresh()->refresh_token)->toBe('rt-unico');
});

it('si Microsoft dice invalid_grant al renovar, marca la conexión vencida, deja bitácora sin tokens y lanza sin mandar', function () {
    $connection = MailConnection::factory()->create(['refresh_token' => 'rt-revocado', 'access_token_expires_at' => now()->subMinute()]);

    Http::fake([
        GRAPH_TOKEN => Http::response(['error' => 'invalid_grant', 'error_description' => "AADSTS70008: The provided authorization code or refresh token has expired.\r\nTrace ID: abc"], 400),
        GRAPH_SEND => Http::response(null, 202),
    ]);

    expect(fn () => sendThroughGraph())->toThrow(function (TransportException $exception) {
        expect($exception->getMessage())->toContain('venció')
            ->toContain('invalid_grant')
            ->toContain('AADSTS70008')
            ->not->toContain('Trace ID')
            ->not->toContain('rt-revocado')
            ->not->toContain('secreto-prueba');
    });

    Http::assertNotSent(fn (Request $request): bool => $request->url() === GRAPH_SEND);

    $fresh = $connection->fresh();
    $entry = Activity::query()->where('event', 'mail-account-expired')->sole();

    expect($fresh->expired_at)->not->toBeNull()
        ->and($fresh->last_error)->toContain('invalid_grant')
        ->and($entry->subject_id)->toBe($connection->id)
        ->and(json_encode($entry->properties))->not->toContain('rt-revocado');
});

it('con la conexión ya vencida no llama a Microsoft y pide reconectar', function () {
    MailConnection::factory()->expired()->create();
    Http::fake();

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'Reconecta la cuenta de envío');

    Http::assertNothingSent();
});

it('sin cuenta conectada lanza y no llama a Microsoft', function () {
    Http::fake();

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'No hay una cuenta de envío conectada');

    Http::assertNothingSent();
});

it('si Graph rechaza el correo, lanza con su código y su mensaje y lo anota en la conexión', function () {
    $connection = MailConnection::factory()->create(['access_token' => 'at-vigente']);
    Http::fake([GRAPH_SEND => Http::response(['error' => ['code' => 'ErrorInvalidRecipients', 'message' => 'At least one recipient is not valid.']], 400)]);

    expect(fn () => sendThroughGraph())->toThrow(function (TransportException $exception) {
        expect($exception->getMessage())->toContain('HTTP 400')
            ->toContain('ErrorInvalidRecipients')
            ->toContain('At least one recipient is not valid.')
            ->not->toContain('at-vigente');
    });

    expect($connection->fresh()->last_error)->toContain('ErrorInvalidRecipients')
        ->and($connection->fresh()->expired_at)->toBeNull();
});

it('si Graph responde 401 con un token vigente, renueva una vez y vuelve a mandar', function () {
    MailConnection::factory()->create(['access_token' => 'at-rechazado', 'refresh_token' => 'rt-1', 'access_token_expires_at' => now()->addHour()]);

    Http::fake([
        GRAPH_TOKEN => Http::response(['access_token' => 'at-otro', 'refresh_token' => 'rt-2', 'expires_in' => 3600]),
        GRAPH_SEND => Http::sequence()->push(['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token has expired.']], 401)->push(null, 202),
    ]);

    sendThroughGraph();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->url() === GRAPH_SEND && $request->hasHeader('Authorization', 'Bearer at-otro'));
});

it('si Microsoft rechaza la app (secreto vencido), lo dice sin marcar vencida la conexión', function () {
    $connection = MailConnection::factory()->create(['access_token_expires_at' => now()->subMinute()]);
    Http::fake([GRAPH_TOKEN => Http::response(['error' => 'invalid_client', 'error_description' => 'AADSTS7000222: The provided client secret keys are expired.'], 401)]);

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'MICROSOFT_GRAPH_CLIENT_SECRET');

    expect($connection->fresh()->expired_at)->toBeNull()
        ->and($connection->fresh()->last_error)->toContain('invalid_client');
});

it('sin las variables de Azure lanza y dice cuáles faltan', function () {
    MailConnection::factory()->create();
    config(['services.microsoft_graph.client_secret' => null]);
    Http::fake();

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'MICROSOFT_GRAPH_CLIENT_SECRET');

    Http::assertNothingSent();
});

it('el mailer microsoft-graph está en config/mail.php', function () {
    expect(config('mail.mailers.microsoft-graph'))->toBe(['transport' => 'microsoft-graph'])
        ->and((string) Mail::mailer('microsoft-graph')->getSymfonyTransport())->toBe('microsoft-graph');
});
