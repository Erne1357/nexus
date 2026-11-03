<?php

use App\Modules\AcademicLoad\Notifications\ProposalReturned;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Actions\MarkAllNotificationsRead;
use App\Modules\Core\Actions\OpenNotification;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AddressedToRole;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use App\Modules\Core\Queries\NotificationQuery;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| Tarea 2.9: notificaciones en base de datos. Cada módulo define las suyas sobre NexusNotification, que
| guarda un mensaje (título, detalle, icono y destino como ruta con parámetros, más el periodo y el campus
| del aviso). La campana las lee con NotificationQuery y las abre con OpenNotification.
*/

final class TestNotice extends NexusNotification
{
    public function __construct(private readonly NotificationMessage $message) {}

    public function message(object $notifiable): NotificationMessage
    {
        return $this->message;
    }
}

final class TestJefeNotice extends NexusNotification implements AddressedToRole
{
    public function __construct(private readonly NotificationMessage $message) {}

    public static function addressedRole(): Role
    {
        return Role::JefeDepartamento;
    }

    public function message(object $notifiable): NotificationMessage
    {
        return $this->message;
    }
}

function notice(string $title = 'Aviso', ?string $route = 'home', array $parameters = [], ?string $termId = null, ?string $campusId = null): TestNotice
{
    return new TestNotice(new NotificationMessage(
        title: $title,
        body: 'Detalle del aviso',
        icon: 'o-bell',
        route: $route,
        parameters: $parameters,
        termId: $termId,
        campusId: $campusId,
    ));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', 'America/Ciudad_Juarez'));
});

it('guarda el mensaje en la base con una llave UUIDv7 distinta por destinatario', function () {
    [$ana, $beto] = User::factory()->count(2)->create();

    Notification::send([$ana, $beto], notice('Tienes catálogos por validar', 'home', ['department' => 'x']));

    $stored = DatabaseNotification::query()->orderBy('id')->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('id')->unique())->toHaveCount(2)
        ->and($stored->every(fn (DatabaseNotification $notification): bool => Str::isUuid($notification->id, 7)))->toBeTrue()
        ->and($stored->first()->data)->toEqual([
            'title' => 'Tienes catálogos por validar',
            'body' => 'Detalle del aviso',
            'icon' => 'o-bell',
            'tone' => 'info',
            'route' => 'home',
            'parameters' => ['department' => 'x'],
            'term_id' => null,
            'campus_id' => null,
        ]);
});

it('la campana cuenta las no leídas y lista las recientes del usuario, las nuevas primero', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();

    $ana->notify(notice('Primera'));
    $this->travel(5)->minutes();
    $ana->notify(notice('Segunda'));
    $beto->notify(notice('De Beto'));
    $ana->notifications()->first()->markAsRead();

    $query = app(NotificationQuery::class);

    expect($query->unreadCount($ana))->toBe(1)
        ->and($query->recent($ana)->pluck('title')->all())->toBe(['Segunda', 'Primera'])
        ->and($query->recent($ana)->pluck('read')->all())->toBe([true, false]);
});

it('abrir una notificación la marca leída y regresa la dirección de su ruta', function () {
    $ana = User::factory()->create();
    $ana->notify(notice(route: 'home'));
    $id = $ana->notifications()->value('id');
    $this->actingAs($ana);

    $target = app(OpenNotification::class)->handle($ana, $id);

    expect($target->url)->toBe(route('home'))
        ->and($target->problem)->toBeNull()
        ->and($ana->unreadNotifications()->count())->toBe(0);
});

it('si la pantalla ya no existe, avisa en lugar de llevarlo y la marca leída', function () {
    $ana = User::factory()->create();
    $ana->notify(notice(route: 'pantalla-que-no-existe', parameters: ['department' => 'x']));
    $this->actingAs($ana);

    $target = app(OpenNotification::class)->handle($ana, $ana->notifications()->value('id'));

    expect($target->url)->toBeNull()
        ->and($target->problem)->toBe('Esa pantalla no está disponible.')
        ->and($ana->unreadNotifications()->count())->toBe(0);
});

it('si la ruta ya no acepta los parámetros guardados, avisa en lugar de fallar', function () {
    Route::get('/prueba/{term}/{department}', fn () => 'ok')->name('prueba.con-parametros');
    $ana = User::factory()->create();
    $ana->notify(notice(route: 'prueba.con-parametros', parameters: ['department' => 'x']));
    $this->actingAs($ana);

    $target = app(OpenNotification::class)->handle($ana, $ana->notifications()->value('id'));

    expect($target->url)->toBeNull()
        ->and($target->problem)->toBe('Esa pantalla no está disponible.');
});

it('solo abre notificaciones del usuario de la sesión', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $ana->notify(notice());
    $this->actingAs($beto);

    expect(fn () => app(OpenNotification::class)->handle($ana, $ana->notifications()->value('id')))
        ->toThrow(AuthorizationException::class, 'Solo puedes abrir tus notificaciones.');
});

it('si ya no trabaja en el campus del aviso, avisa en lugar de llevarlo', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $c1->id, 'career_id' => careerKey(20)])->create();
    $coordinador->notify(notice(campusId: $c2->id));
    $this->actingAs($coordinador);

    $target = app(OpenNotification::class)->handle($coordinador, $coordinador->notifications()->value('id'));

    expect($target->url)->toBeNull()
        ->and($target->problem)->toBe('Ya no trabajas en el campus de ese aviso.');
});

it('abrirla cambia la credencial al periodo y el campus del aviso si el usuario trabaja ahí', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $term = Term::factory()->create(['is_current' => false]);
    $admin = User::factory()->admin()->create();
    $admin->notify(notice(termId: $term->id, campusId: $c2->id));
    $this->actingAs($admin);

    app(OpenNotification::class)->handle($admin, $admin->notifications()->value('id'));

    $context = app(ScopedContext::class);
    expect($context->activeTerm()?->id)->toBe($term->id)
        ->and($context->activeCampus()?->id)->toBe($c2->id);
});

it('no abre la notificación de otro usuario', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $beto->notify(notice());
    $this->actingAs($ana);

    expect(fn () => app(OpenNotification::class)->handle($ana, $beto->notifications()->value('id')))
        ->toThrow(AuthorizationException::class);

    expect($beto->unreadNotifications()->count())->toBe(1);
});

it('marcar todas como leídas solo toca las del usuario', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $ana->notify(notice());
    $ana->notify(notice());
    $beto->notify(notice());

    expect(app(MarkAllNotificationsRead::class)->handle($ana))->toBe(2)
        ->and($ana->unreadNotifications()->count())->toBe(0)
        ->and($beto->unreadNotifications()->count())->toBe(1);
});

it('abrir un aviso de jefe con Coordinación activa cambia a Jefatura y abre el destino', function () {
    $ana = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])
        ->create();
    $ana->notify(new TestJefeNotice(new NotificationMessage(title: 'Aviso de jefe', body: 'x', icon: 'o-bell', route: 'home')));
    actingAsRole($ana, Role::Coordinador);

    $target = app(OpenNotification::class)->handle($ana, $ana->notifications()->value('id'));

    expect($target->url)->toBe(route('home'))
        ->and(app(ScopedContext::class)->activeRole())->toBe(Role::JefeDepartamento)
        ->and($ana->fresh()->last_role)->toBe(Role::JefeDepartamento);
});

it('si ya no tiene el puesto del aviso, no cambia de perfil', function () {
    $ana = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])
        ->create();
    $ana->notify(new TestJefeNotice(new NotificationMessage(title: 'Aviso de jefe', body: 'x', icon: 'o-bell', route: 'home')));
    actingAsRole($ana, Role::Coordinador);

    $target = app(OpenNotification::class)->handle($ana, $ana->notifications()->value('id'));

    expect($target->url)->toBe(route('home'))
        ->and(app(ScopedContext::class)->activeRole())->toBe(Role::Coordinador)
        ->and($ana->fresh()->last_role)->toBeNull();
});

it('un aviso que ya no se puede abrir no cambia el perfil', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $ana = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10), 'campus_id' => $c1->id])
        ->withRole(Role::Coordinador, ['campus_id' => $c2->id, 'career_id' => careerKey(20)])
        ->create();
    // Un aviso de jefe en C2, donde Ana solo coordina: como jefa ya no lo puede abrir, aunque como coordinadora sí
    // trabaje en C2.
    $ana->notify(new TestJefeNotice(new NotificationMessage(title: 'Aviso de jefe', body: 'x', icon: 'o-bell', route: 'home', campusId: $c2->id)));
    actingAsRole($ana, Role::Coordinador);
    session(['core.recent_pages' => ['academico' => '/campus']]);

    $component = Livewire::test('core::components.bell')
        ->call('open', $ana->notifications()->value('id'))
        ->assertNoRedirect();

    expect(lastToastOf($component))->toMatchArray(['type' => 'warning', 'title' => 'Ya no trabajas en el campus de ese aviso.'])
        ->and(session(ScopedContext::ACTIVE_ROLE))->toBe(Role::Coordinador->value)
        ->and($ana->fresh()->last_role)->toBeNull()
        ->and(session('core.recent_pages'))->toBe(['academico' => '/campus']);
});

it('un aviso que cambia de perfil abre su pantalla con una carga completa, sin refrescar los componentes de la página vieja', function () {
    $ana = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])
        ->create();
    $ana->notify(new TestJefeNotice(new NotificationMessage(title: 'Aviso de jefe', body: 'x', icon: 'o-bell', route: 'home')));
    $ana->notify(notice('Aviso sin puesto'));
    actingAsRole($ana, Role::Coordinador);

    $switching = Livewire::test('core::components.bell')
        ->call('open', $ana->notifications()->where('type', TestJefeNotice::class)->value('id'))
        ->assertRedirect(route('home'))
        ->assertNotDispatched('notifications-changed');

    expect($switching->effects)->not->toHaveKey('redirectUsingNavigate')
        ->and(session(ScopedContext::ACTIVE_ROLE))->toBe(Role::JefeDepartamento->value);

    // Sin cambio de perfil sigue navegando con wire:navigate y avisa a la campana y a la página.
    $plain = Livewire::test('core::components.bell')
        ->call('open', $ana->notifications()->where('type', TestNotice::class)->value('id'))
        ->assertRedirect(route('home'))
        ->assertDispatched('notifications-changed');

    expect($plain->effects['redirectUsingNavigate'] ?? false)->toBeTrue();
});

it('los avisos de jefe y de coordinación declaran su puesto', function () {
    expect(ProposalSubmitted::addressedRole())->toBe(Role::JefeDepartamento)
        ->and(ProposalReturned::addressedRole())->toBe(Role::Coordinador)
        ->and(CatalogReviewsPending::addressedRole())->toBe(Role::JefeDepartamento);
});
