<?php

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| «Correos y colas» (`/configuracion/correos-y-colas`, core::pages.mail-and-queues; CF-S8, CF-03): solo del admin. Tres
| pestañas: Correos, Invitaciones (con «Reenviar») y Colas (tamaños y fallidos con Reintentar, Descartar y Ver error).
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function pageFailedJob(string $name = 'App\\Jobs\\Demo'): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 1, 'data' => []]),
        'exception' => "RuntimeException: se cayó\n#0 pila completa",
        'failed_at' => now(),
    ]);

    return $uuid;
}

it('el admin entra por la ruta y ve el registro de correos con sus filtros', function () {
    MailLog::factory()->create(['to' => 'ana@itcj.test', 'subject' => 'Tu acceso']);

    $this->actingAs($this->admin)->get(route('core.mail-and-queues'))
        ->assertOk()
        ->assertSee('Correos y colas')
        ->assertSee('ana@itcj.test')
        ->assertSee('Tu acceso');

    expect(route('core.mail-and-queues', absolute: false))->toBe('/configuracion/correos-y-colas');
});

it('filtra los correos por texto y por estado', function () {
    MailLog::factory()->create(['to' => 'ana@itcj.test']);
    MailLog::factory()->failed()->create(['to' => 'beto@itcj.test']);

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->set('search', 'beto')
        ->assertSee('beto@itcj.test')
        ->assertDontSee('ana@itcj.test')
        ->set('search', '')
        ->set('status', 'sent')
        ->assertSee('ana@itcj.test')
        ->assertDontSee('beto@itcj.test');
});

it('CF-S8: la pestaña Invitaciones lista los pendientes y «Reenviar» llama a SendAccessInvitation', function () {
    $user = User::factory()->create(['password' => null, 'email' => 'pend@itcj.test', 'access_invited_at' => now()->subDays(3)->startOfSecond()]);

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->call('showTab', 'invitations')
        ->assertSee('pend@itcj.test')
        ->call('resend', $user->id)
        ->assertHasNoErrors();

    $fresh = $user->fresh();

    expect($fresh->access_invited_at->isAfter(now()->subMinute()))->toBeTrue()
        ->and(Activity::query()->where('event', 'access-invited')->where('causer_id', $this->admin->id)->exists())->toBeTrue()
        ->and(MailLog::query()->where('to', 'pend@itcj.test')->exists())->toBeTrue();
});

it('CF-S8: si el usuario tiene un puesto, «Reenviar» muestra el motivo y no manda nada', function () {
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])
        ->create(['password' => null, 'email' => 'jefe@itcj.test', 'access_invited_at' => now()->subDay()->startOfSecond()]);

    $page = Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->call('resend', $user->id);

    expect(lastToastOf($page))->title->toBe('No se reenvió la invitación')->description->toBe(e(SendAccessInvitation::STAFF))
        ->and(MailLog::query()->count())->toBe(0);
});

it('CF-S8: la pestaña Colas muestra los tamaños de default y bulk y los trabajos fallidos', function () {
    pageFailedJob('App\\Jobs\\TrabajoQueFalla');

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->call('showTab', 'queues')
        ->assertSeeHtml('data-testid="queue-default"')
        ->assertSeeHtml('data-testid="queue-bulk"')
        ->assertSee('App\\Jobs\\TrabajoQueFalla');
});

it('CF-S8: Reintentar y Descartar operan sobre el trabajo y dejan bitácora', function () {
    $retry = pageFailedJob();
    $forget = pageFailedJob('App\\Jobs\\Otro');

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->call('showTab', 'queues')
        ->call('retry', $retry)
        ->call('forget', $forget);

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(Activity::query()->whereIn('event', ['failed-job-retried', 'failed-job-forgotten'])->count())->toBe(2);
});

it('Ver error abre el drawer con la excepción completa', function () {
    $uuid = pageFailedJob();

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->call('showTab', 'queues')
        ->call('openError', $uuid)
        ->assertSet('showError', true)
        ->assertSee('#0 pila completa');
});

it('CF-03: otro puesto no entra, ni llama las acciones directo', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();
    $uuid = pageFailedJob();

    $this->actingAs($user)->get(route('core.mail-and-queues'))->assertForbidden();

    Livewire::actingAs($user)->test('core::pages.mail-and-queues')->assertForbidden();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('CF-03: el menú muestra «Correos y colas» solo al admin', function () {
    $this->actingAs($this->admin)->get(route('home'))->assertSee('Correos y colas');

    $docente = User::factory()->withRole(Role::Docente)->create();
    $this->actingAs($docente)->get(route('home'))->assertDontSee('Correos y colas');
});

it('CF-03: con la página que abrió el admin, otro usuario no puede reenviar, reintentar, descartar ni abrir un error', function () {
    $uuid = pageFailedJob();
    $invited = User::factory()->create(['password' => null, 'email' => 'pend@itcj.test', 'access_invited_at' => now()->subDay()->startOfSecond()]);
    $docente = User::factory()->withRole(Role::Docente)->create();

    $open = fn () => Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')->call('showTab', 'queues');
    $calls = [['retry', $uuid], ['forget', $uuid], ['openError', $uuid], ['resend', $invited->id]];
    $pages = array_map(fn () => $open(), $calls);
    $this->actingAs($docente);

    foreach ($calls as $index => [$method, $argument]) {
        $pages[$index]->call($method, $argument)->assertForbidden();
    }

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(MailLog::query()->count())->toBe(0)
        ->and(Activity::query()->whereIn('event', ['failed-job-retried', 'failed-job-forgotten'])->count())->toBe(0);
});

it('un rango de fechas manipulado no rompe la página: se descarta', function () {
    MailLog::factory()->create(['to' => 'ana@itcj.test']);

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->set('from', '2026-13-45')
        ->set('to', 'x')
        ->assertOk()
        ->assertSee('ana@itcj.test');
});

it('el drawer del error corta la excepción a 40 líneas y oculta tokens y firmas', function () {
    $uuid = pageFailedJob();
    $exception = "RuntimeException: https://nexus.test/access/1/h?expires=1&signature=SECRETOFIRMA\n".implode("\n", array_map(fn (int $n): string => "#{$n} pila token=SECRETOTOKEN", range(1, 60)));
    DB::table('failed_jobs')->where('uuid', $uuid)->update(['exception' => $exception]);

    Livewire::actingAs($this->admin)->test('core::pages.mail-and-queues')
        ->call('showTab', 'queues')
        ->call('openError', $uuid)
        ->assertSee('#38 pila')
        ->assertDontSee('#45 pila')
        ->assertSee('líneas más')
        ->assertDontSee('SECRETOFIRMA')
        ->assertDontSee('SECRETOTOKEN')
        ->assertSee('signature=[oculto]', false);
});
