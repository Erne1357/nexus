<?php

use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Support\QueueJobScope;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/*
| Tarea 1.29 (ADR-0032): el transporte `microsoft-graph` manda cada correo con `POST /me/sendMail` de Microsoft Graph,
| como la cuenta conectada en «Correos y colas». Antes de mandar renueva el token si le quedan menos de 5 minutos y
| guarda el refresh token rotado; si Microsoft dice que la conexión venció la marca vencida y lanza. Todo error es una
| TransportException con el mensaje de Microsoft, sin tokens, para que el trabajo de la cola falle y se reintente.
|
| Límites de Exchange Online (unos 30 correos por minuto por buzón): el transporte lleva su propio ritmo (5 cada 15 s,
| en la caché compartida) y, ante un 429 (o un 503 con Retry-After), espera lo que pide Microsoft y reintenta hasta 2
| veces, con un tope de 30 s de espera por correo. Solo espera dentro de un trabajo del worker de la cola; en una
| petición web falla en el acto.
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

it('en un trabajo de la cola, ante un 429 espera lo que dice Retry-After y vuelve a mandar en el mismo envío', function () {
    Sleep::fake();
    app(QueueJobScope::class)->begin();
    MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::sequence()
        ->push(['error' => ['code' => 'ApplicationThrottled', 'message' => 'Too many requests.']], 429, ['Retry-After' => '7'])
        ->push(null, 202)]);

    sendThroughGraph();

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(7)->seconds()]);
});

it('un 429 sin Retry-After espera 30 s, y un 503 con Retry-After también es freno', function () {
    Sleep::fake();
    app(QueueJobScope::class)->begin();
    MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::sequence()
        ->push(['error' => ['code' => 'ApplicationThrottled', 'message' => 'Too many requests.']], 429)
        ->push(null, 202)
        ->push(['error' => ['code' => 'ServiceUnavailable', 'message' => 'Busy.']], 503, ['Retry-After' => '3'])
        ->push(null, 202)]);

    sendThroughGraph();
    sendThroughGraph();

    Sleep::assertSequence([Sleep::for(30)->seconds(), Sleep::for(3)->seconds()]);
});

it('un 503 sin Retry-After es un error normal: no espera', function () {
    Sleep::fake();
    app(QueueJobScope::class)->begin();
    MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::response(['error' => ['code' => 'ServiceUnavailable', 'message' => 'Busy.']], 503)]);

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'HTTP 503');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('después de 2 reintentos por 429 lanza un error claro para que la cola lo vuelva a intentar', function () {
    Sleep::fake();
    app(QueueJobScope::class)->begin();
    $connection = MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::response(['error' => ['code' => 'ApplicationThrottled', 'message' => 'Too many requests.']], 429, ['Retry-After' => '2'])]);

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'Microsoft siguió frenando el envío');

    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);

    expect($connection->fresh()->expired_at)->toBeNull();
});

it('no espera más de 30 s por correo: si Retry-After pide más de lo que queda, lanza y deja el reintento a la cola', function () {
    Sleep::fake();
    app(QueueJobScope::class)->begin();
    MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::sequence()
        ->push(['error' => ['code' => 'ApplicationThrottled', 'message' => 'Too many requests.']], 429, ['Retry-After' => '20'])
        ->push(['error' => ['code' => 'ApplicationThrottled', 'message' => 'Too many requests.']], 429, ['Retry-After' => '20'])]);

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'Microsoft pidió esperar 20 s');

    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(20)->seconds()]);
});

it('en una petición web no espera: ante un 429 falla en el acto', function () {
    Sleep::fake();
    MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::response(['error' => ['code' => 'ApplicationThrottled', 'message' => 'Too many requests.']], 429, ['Retry-After' => '5'])]);

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'Microsoft está frenando el envío; intenta en un minuto.');

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('lleva su propio ritmo en la caché compartida (5 correos cada 15 s): en la cola, el sexto espera su lugar', function () {
    // Con el reloj quieto, los cinco lugares se toman en el mismo segundo: si el segundo cambiaba entre el primero y el
    // sexto, la espera salía en dos tramos (14 s y 1 s) y la prueba fallaba de vez en cuando en la CI.
    $this->freezeSecond();
    Sleep::fake(syncWithCarbon: true);
    app(QueueJobScope::class)->begin();
    MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::response(null, 202)]);

    foreach (range(1, 6) as $mail) {
        sendThroughGraph();
    }

    Http::assertSentCount(6);
    Sleep::assertSequence([Sleep::for(15)->seconds()]);

    expect(RateLimiter::attempts('core:mail:graph'))->toBe(1);
});

it('en una petición web, con el ritmo lleno, falla en el acto sin llamar a Microsoft', function () {
    Sleep::fake();
    MailConnection::factory()->create();
    Http::fake([GRAPH_SEND => Http::response(null, 202)]);

    foreach (range(1, 5) as $mail) {
        sendThroughGraph();
    }

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'intenta en un minuto');

    Http::assertSentCount(5);
    Sleep::assertNeverSlept();
});

it('sabe que corre en un trabajo de la cola por JobProcessing de una conexión que no es sync, y lo olvida al terminar', function () {
    $job = Mockery::mock(Job::class);
    $job->allows('payload')->andReturn([]);
    // El listener de la línea canónica del job (LogJobOutcome) también atiende estos eventos y lee el contrato Job.
    $job->allows(['resolveName' => 'App\Jobs\Prueba', 'getQueue' => 'default', 'attempts' => 1, 'isReleased' => false, 'hasFailed' => false]);
    $scope = fn (): bool => app(QueueJobScope::class)->inWorkerJob();

    expect($scope())->toBeFalse();

    event(new JobProcessing('sync', $job));
    expect($scope())->toBeFalse();

    event(new JobProcessing('redis', $job));
    expect($scope())->toBeTrue();

    event(new JobProcessed('redis', $job));
    expect($scope())->toBeFalse();

    event(new JobProcessing('redis', $job));
    event(new JobExceptionOccurred('redis', $job, new RuntimeException('falló')));
    expect($scope())->toBeFalse();
});

it('MICROSOFT_GRAPH_TENANT_ID común (common, organizations, consumers) es configuración inválida: no manda', function (string $tenant) {
    MailConnection::factory()->create();
    config(['services.microsoft_graph.tenant_id' => $tenant]);
    Http::fake();

    expect(fn () => sendThroughGraph())->toThrow(TransportException::class, 'MICROSOFT_GRAPH_TENANT_ID');

    Http::assertNothingSent();
})->with(['common', 'organizations', 'Consumers']);

it('el mailer microsoft-graph está en config/mail.php', function () {
    expect(config('mail.mailers.microsoft-graph'))->toBe(['transport' => 'microsoft-graph'])
        ->and((string) Mail::mailer('microsoft-graph')->getSymfonyTransport())->toBe('microsoft-graph');
});
