<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Mail\MicrosoftSignIn;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\MailConnection;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Tarea 1.29 (ADR-0032, CF-03, CF-S9): la cuenta de envío de «Correos y colas». El admin la conecta con Microsoft
| (Authorization Code con PKCE y `state`, permisos delegados), la desconecta con confirmación y manda un correo de
| prueba. El regreso de Microsoft (`/configuracion/correo/microsoft/callback`) valida el `state`, cambia el código por
| los tokens, lee la cuenta con `GET /me` y la guarda con los tokens cifrados. Ningún otro puesto ve ni usa nada de esto.
*/

beforeEach(function () {
    config(['services.microsoft_graph' => ['tenant_id' => 'tenant-prueba', 'client_id' => 'cliente-prueba', 'client_secret' => 'secreto-prueba']]);
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create(['email' => 'admin@cdjuarez.tecnm.mx']);
});

/**
 * Lo que el inicio de la conexión dejó en la sesión.
 *
 * @return array<string, mixed>
 */
function pendingSignIn(string $userId, string $state = 'estado-bueno', string $verifier = 'verificador-de-prueba-con-mas-de-cuarenta-y-tres-caracteres'): array
{
    return [MicrosoftSignIn::SESSION_KEY => ['state' => $state, 'verifier' => $verifier, 'user' => $userId, 'at' => now()->getTimestamp()]];
}

function fakeMicrosoftSignIn(string $mail = 'nexus@cdjuarez.tecnm.mx'): void
{
    Http::fake([
        'https://login.microsoftonline.com/tenant-prueba/oauth2/v2.0/token' => Http::response([
            'token_type' => 'Bearer',
            'scope' => 'https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read',
            'access_token' => 'at-conexion',
            'refresh_token' => 'rt-conexion',
            'expires_in' => 3600,
        ]),
        'https://graph.microsoft.com/v1.0/me*' => Http::response(['mail' => $mail, 'userPrincipalName' => 'otro@cdjuarez.tecnm.mx']),
    ]);
}

it('«Conectar con Microsoft» guarda el state y el verificador en la sesión y lleva al inicio de sesión de Microsoft con PKCE', function () {
    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')->call('connectMicrosoft');

    $page->assertRedirectContains('https://login.microsoftonline.com/tenant-prueba/oauth2/v2.0/authorize?');
    parse_str((string) parse_url($page->effects['redirect'], PHP_URL_QUERY), $query);
    $pending = session(MicrosoftSignIn::SESSION_KEY);

    expect($query)->toMatchArray([
        'client_id' => 'cliente-prueba',
        'response_type' => 'code',
        'response_mode' => 'query',
        'redirect_uri' => route('core.mail-connection.callback'),
        'scope' => 'offline_access https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read',
        'state' => $pending['state'],
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ])
        ->and(strlen($pending['state']))->toBeGreaterThanOrEqual(32)
        ->and(strlen($pending['verifier']))->toBeGreaterThanOrEqual(43)
        ->and($pending['user'])->toBe($this->admin->id);

    expect(route('core.mail-connection.callback', absolute: false))->toBe('/configuracion/correo/microsoft/callback');
});

it('la dirección de regreso sale de APP_URL, no del host por el que se entró', function () {
    config(['app.url' => 'https://nexus.cdjuarez.tecnm.mx']);

    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->assertSee('https://nexus.cdjuarez.tecnm.mx/configuracion/correo/microsoft/callback')
        ->call('connectMicrosoft');

    parse_str((string) parse_url($page->effects['redirect'], PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe('https://nexus.cdjuarez.tecnm.mx/configuracion/correo/microsoft/callback');
});

it('sin las variables de Azure, «Conectar con Microsoft» no sale y dice cuáles faltan', function () {
    config(['services.microsoft_graph.client_id' => '']);

    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')->call('connectMicrosoft')->assertNoRedirect();

    expect(lastToastOf($page)['description'])->toContain('MICROSOFT_GRAPH_CLIENT_ID');
});

it('con MICROSOFT_GRAPH_TENANT_ID común la tarjeta dice que está mal configurado y no deja conectar', function (string $tenant) {
    config(['services.microsoft_graph.tenant_id' => $tenant]);

    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->assertSee('Sin configurar')
        ->assertSee("«{$tenant}»")
        ->assertSee('Directory (tenant) ID')
        ->assertDontSeeHtml('data-testid="sending-connect"')
        ->call('connectMicrosoft')
        ->assertNoRedirect();

    expect(lastToastOf($page)['description'])->toContain('MICROSOFT_GRAPH_TENANT_ID')
        ->and(session()->has(MicrosoftSignIn::SESSION_KEY))->toBeFalse();
})->with(['common', 'organizations', 'consumers']);

it('el regreso de Microsoft con el state correcto cambia el código por los tokens y guarda la cuenta con los tokens cifrados', function () {
    fakeMicrosoftSignIn();

    $this->actingAs($this->admin)->withSession(pendingSignIn($this->admin->id))
        ->get(route('core.mail-connection.callback', ['code' => 'codigo-de-microsoft', 'state' => 'estado-bueno']))
        ->assertRedirect(route('core.mail-and-queues'));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/oauth2/v2.0/token')
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'codigo-de-microsoft'
        && $request['code_verifier'] === 'verificador-de-prueba-con-mas-de-cuarenta-y-tres-caracteres'
        && $request['redirect_uri'] === route('core.mail-connection.callback')
        && $request['client_secret'] === 'secreto-prueba');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/me') && $request->hasHeader('Authorization', 'Bearer at-conexion'));

    $connection = MailConnection::query()->sole();
    $raw = (array) DB::table('mail_connections')->first();

    expect($connection->account_email)->toBe('nexus@cdjuarez.tecnm.mx')
        ->and($connection->access_token)->toBe('at-conexion')
        ->and($connection->refresh_token)->toBe('rt-conexion')
        ->and($connection->connected_by)->toBe($this->admin->id)
        ->and($connection->access_token_expires_at->isAfter(now()->addMinutes(55)))->toBeTrue()
        ->and($raw['access_token'])->not->toContain('at-conexion')
        ->and($raw['refresh_token'])->not->toContain('rt-conexion')
        ->and(session()->has(MicrosoftSignIn::SESSION_KEY))->toBeFalse();

    $entry = Activity::query()->where('event', 'mail-account-connected')->sole();

    expect($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties['account'])->toBe('nexus@cdjuarez.tecnm.mx')
        ->and(json_encode($entry->properties))->not->toContain('at-conexion')->not->toContain('rt-conexion')->not->toContain('secreto-prueba');

    $this->get(route('core.mail-and-queues'))->assertSee('Cuenta de envío conectada')->assertSee('nexus@cdjuarez.tecnm.mx');
});

it('sin correo en el buzón, guarda el userPrincipalName en minúsculas', function () {
    Http::fake([
        '*/oauth2/v2.0/token' => Http::response(['scope' => 'https://graph.microsoft.com/Mail.Send', 'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
        'https://graph.microsoft.com/v1.0/me*' => Http::response(['mail' => null, 'userPrincipalName' => 'Nexus@CDJuarez.TecNM.mx']),
    ]);

    $this->actingAs($this->admin)->withSession(pendingSignIn($this->admin->id))
        ->get(route('core.mail-connection.callback', ['code' => 'c', 'state' => 'estado-bueno']));

    expect(MailConnection::query()->sole()->account_email)->toBe('nexus@cdjuarez.tecnm.mx');
});

it('reconectar reemplaza la cuenta anterior: siempre hay una sola conexión', function () {
    MailConnection::factory()->expired()->create(['account_email' => 'vieja@cdjuarez.tecnm.mx']);
    fakeMicrosoftSignIn('nueva@cdjuarez.tecnm.mx');

    $this->actingAs($this->admin)->withSession(pendingSignIn($this->admin->id))
        ->get(route('core.mail-connection.callback', ['code' => 'c', 'state' => 'estado-bueno']));

    $connection = MailConnection::query()->sole();

    expect($connection->account_email)->toBe('nueva@cdjuarez.tecnm.mx')
        ->and($connection->expired_at)->toBeNull()
        ->and($connection->last_error)->toBeNull()
        ->and(Activity::query()->where('event', 'mail-account-connected')->sole()->properties['previous'])->toBe('vieja@cdjuarez.tecnm.mx');
});

it('rechaza el regreso con un state que no es el de la sesión, sin llamar a Microsoft ni guardar nada', function (?array $session, array $query) {
    Http::fake();

    $this->actingAs($this->admin)->withSession($session === null ? [] : pendingSignIn($this->admin->id))
        ->get(route('core.mail-connection.callback', $query))
        ->assertRedirect(route('core.mail-and-queues'));

    Http::assertNothingSent();

    expect(MailConnection::query()->count())->toBe(0);

    $this->get(route('core.mail-and-queues'))->assertSee('No se conectó la cuenta de envío')->assertSee('Conectar con Microsoft');
})->with([
    'otro state' => [[], ['code' => 'c', 'state' => 'estado-falso']],
    'sin state' => [[], ['code' => 'c']],
    'sin conexión iniciada' => [null, ['code' => 'c', 'state' => 'estado-bueno']],
]);

it('rechaza el regreso de una conexión que inició otro usuario o que tardó más de 10 minutos', function () {
    Http::fake();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($this->admin)->withSession(pendingSignIn($otherAdmin->id))
        ->get(route('core.mail-connection.callback', ['code' => 'c', 'state' => 'estado-bueno']));

    $this->travel(11)->minutes();
    $stale = pendingSignIn($this->admin->id);
    $stale[MicrosoftSignIn::SESSION_KEY]['at'] = now()->subMinutes(11)->getTimestamp();

    $this->actingAs($this->admin)->withSession($stale)
        ->get(route('core.mail-connection.callback', ['code' => 'c', 'state' => 'estado-bueno']));

    Http::assertNothingSent();

    expect(MailConnection::query()->count())->toBe(0);
});

it('si el admin no dio permiso en Microsoft, lo dice y no guarda nada', function () {
    Http::fake();

    $this->actingAs($this->admin)->withSession(pendingSignIn($this->admin->id))
        ->get(route('core.mail-connection.callback', ['error' => 'access_denied', 'error_description' => 'AADSTS65004: User declined to consent.', 'state' => 'estado-bueno']))
        ->assertRedirect(route('core.mail-and-queues'));

    Http::assertNothingSent();

    expect(MailConnection::query()->count())->toBe(0);

    $this->get(route('core.mail-and-queues'))->assertSee('AADSTS65004');
});

it('si Microsoft no da Mail.Send o el refresh token, no guarda la conexión y dice qué faltó', function (array $tokens, string $missing) {
    Http::fake([
        '*/oauth2/v2.0/token' => Http::response($tokens),
        'https://graph.microsoft.com/v1.0/me*' => Http::response(['mail' => 'nexus@cdjuarez.tecnm.mx']),
    ]);

    $this->actingAs($this->admin)->withSession(pendingSignIn($this->admin->id))
        ->get(route('core.mail-connection.callback', ['code' => 'c', 'state' => 'estado-bueno']));

    expect(MailConnection::query()->count())->toBe(0);

    $this->get(route('core.mail-and-queues'))->assertSee($missing);
})->with([
    'sin Mail.Send' => [['scope' => 'https://graph.microsoft.com/User.Read', 'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600], 'Mail.Send'],
    'sin refresh token' => [['scope' => 'https://graph.microsoft.com/Mail.Send', 'access_token' => 'at', 'expires_in' => 3600], 'offline_access'],
]);

it('la tarjeta dice si faltan variables, si no hay cuenta, con qué cuenta manda y si la conexión venció', function () {
    config(['services.microsoft_graph.client_secret' => null]);
    $page = fn () => Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues');

    $page()->assertSee('Cuenta de envío')->assertSee('Faltan variables')->assertSee('MICROSOFT_GRAPH_CLIENT_SECRET')
        ->assertSee(route('core.mail-connection.callback'));

    config(['services.microsoft_graph.client_secret' => 'secreto-prueba']);
    $page()->assertSee('Sin conectar')->assertSee('Conectar con Microsoft')->assertDontSee('Enviar correo de prueba');

    $connection = MailConnection::factory()->create([
        'account_email' => 'nexus@cdjuarez.tecnm.mx',
        'connected_at' => now()->setDate(2026, 11, 22)->setTime(9, 30),
        'last_used_at' => now()->setDate(2026, 11, 22)->setTime(10, 5),
        'access_token' => 'acceso-secreto-1',
        'refresh_token' => 'renovar-secreto-1',
    ]);
    $page()->assertSee('Conectada')->assertSee('nexus@cdjuarez.tecnm.mx')->assertSee('22/11/2026 09:30')->assertSee('22/11/2026 10:05')
        ->assertSee('Enviar correo de prueba')->assertSee('Desconectar')->assertDontSee('Conectar con Microsoft')
        ->assertDontSee('acceso-secreto-1')->assertDontSee('renovar-secreto-1');

    $connection->update(['expired_at' => now(), 'last_error' => 'La conexión con Microsoft venció (invalid_grant)']);
    $page()->assertSee('Venció')->assertSee('Reconectar con Microsoft')->assertSee('invalid_grant');
});

it('avisa si MAIL_MAILER no es microsoft-graph o si MAIL_FROM_ADDRESS no es la cuenta conectada', function () {
    config(['mail.default' => 'log', 'mail.from.address' => 'otra@cdjuarez.tecnm.mx']);
    MailConnection::factory()->create(['account_email' => 'nexus@cdjuarez.tecnm.mx']);

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->assertSee('MAIL_MAILER=microsoft-graph')
        ->assertSee('otra@cdjuarez.tecnm.mx');
});

it('«Desconectar» borra la cuenta y sus tokens y deja bitácora sin tokens', function () {
    $connection = MailConnection::factory()->create(['account_email' => 'nexus@cdjuarez.tecnm.mx', 'access_token' => 'at-borrar', 'refresh_token' => 'rt-borrar']);

    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->assertSeeHtml('x-confirm=')
        ->call('disconnectAccount');

    expect(MailConnection::query()->count())->toBe(0)
        ->and(DB::table('mail_connections')->count())->toBe(0)
        ->and(lastToastOf($page)['title'])->toBe('Cuenta de envío desconectada');

    $entry = Activity::query()->where('event', 'mail-account-disconnected')->sole();

    expect($entry->subject_id)->toBe($connection->id)
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties['account'])->toBe('nexus@cdjuarez.tecnm.mx')
        ->and(json_encode($entry->properties))->not->toContain('at-borrar')->not->toContain('rt-borrar');
});

it('«Enviar correo de prueba» lo manda por Graph al correo del admin y queda en el registro de correos', function () {
    MailConnection::factory()->create(['account_email' => 'nexus@cdjuarez.tecnm.mx', 'access_token' => 'at-vigente']);
    config(['mail.default' => 'log']);
    Http::fake(['https://graph.microsoft.com/v1.0/me/sendMail' => Http::response(null, 202)]);

    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')->call('sendTestMail');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.microsoft.com/v1.0/me/sendMail'
        && $request['message']['toRecipients'] === [['emailAddress' => ['address' => 'admin@cdjuarez.tecnm.mx']]]
        && $request['message']['subject'] === 'Correo de prueba de Nexus');

    expect(lastToastOf($page)['title'])->toBe('Correo de prueba enviado')
        ->and(lastToastOf($page)['description'])->toContain('admin@cdjuarez.tecnm.mx')
        ->and(MailLog::query()->where('kind', 'MailAccountTest')->where('to', 'admin@cdjuarez.tecnm.mx')->exists())->toBeTrue();
});

it('el correo de prueba va a la cuenta conectada si el admin no tiene correo, y un rechazo de Graph sale en el aviso', function () {
    $this->admin->update(['email' => null]);
    MailConnection::factory()->create(['account_email' => 'nexus@cdjuarez.tecnm.mx']);
    Http::fake(['https://graph.microsoft.com/v1.0/me/sendMail' => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied.']], 403)]);

    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')->call('sendTestMail');

    Http::assertSent(fn (Request $request): bool => $request['message']['toRecipients'] === [['emailAddress' => ['address' => 'nexus@cdjuarez.tecnm.mx']]]);

    expect(lastToastOf($page)['title'])->toBe('No salió el correo de prueba')
        ->and(lastToastOf($page)['description'])->toContain('ErrorAccessDenied');
});

it('CF-03: otro puesto no usa el regreso de Microsoft ni las acciones de la cuenta, aunque traiga la página del admin', function (Role $role, array $scope) {
    Http::fake();
    MailConnection::factory()->create();
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

    $this->actingAs($user)->withSession(pendingSignIn($user->id))
        ->get(route('core.mail-connection.callback', ['code' => 'c', 'state' => 'estado-bueno']))
        ->assertForbidden();

    $pages = [];
    foreach (['connectMicrosoft', 'disconnectAccount', 'sendTestMail'] as $method) {
        $pages[$method] = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues');
    }

    $this->actingAs($user);

    foreach ($pages as $method => $page) {
        $page->call($method)->assertForbidden();
    }

    Http::assertNothingSent();

    expect(MailConnection::query()->count())->toBe(1);
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('el regreso de Microsoft pide sesión', function () {
    $this->get(route('core.mail-connection.callback', ['code' => 'c', 'state' => 'estado-bueno']))->assertRedirect(route('login'));
});
