<?php

use AlphaNs\SameName;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
| «Todas las notificaciones» (core.notifications): la lista completa del usuario, paginada, con el filtro
| Todas / Sin leer, «Marcar todas como leídas» y el mismo flujo de abrir que la campana (OpenNotification).
| Solo ve y abre las suyas.
*/

final class PageNotice extends NexusNotification
{
    public function __construct(private readonly string $title, private readonly ?string $route = 'home', private readonly ?string $campusId = null) {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: $this->title, body: 'Detalle', icon: 'o-bell', route: $this->route, campusId: $this->campusId);
    }

    public static function typeLabel(): string
    {
        return 'Aviso de prueba';
    }
}

final class OtherPageNotice extends NexusNotification
{
    public function __construct(private readonly string $title) {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: $this->title, body: 'Detalle', icon: 'o-bell', route: 'home');
    }

    public static function typeLabel(): string
    {
        return 'Otro aviso';
    }
}

it('exige sesión', function () {
    $this->get(route('core.notifications'))->assertRedirect(route('login'));
});

it('lista todas las notificaciones del usuario, sin las de otros', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Aviso de Ana'));
    User::factory()->create()->notify(new PageNotice('Aviso de Beto'));

    $this->actingAs($ana)->get(route('core.notifications'))->assertOk()
        ->assertSee('Todas las notificaciones')
        ->assertSee('Aviso de Ana')
        ->assertDontSee('Aviso de Beto');
});

it('pagina de 25 en 25, de la más nueva a la más vieja', function () {
    $ana = User::factory()->create();

    foreach (range(1, 30) as $number) {
        $this->travel(1)->minutes();
        $ana->notify(new PageNotice("Aviso {$number}"));
    }

    $page = Livewire::actingAs($ana)->test('core::pages.notifications');
    $page->assertSee('Aviso 30')->assertSee('Aviso 6')->assertDontSee('Aviso 5')
        ->assertSeeHtmlInOrder(['Aviso 30', 'Aviso 29']);

    $page->call('gotoPage', 2)->assertSee('Aviso 5')->assertSee('Aviso 1')->assertDontSee('Aviso 30');
});

it('el filtro Sin leer, que va en la URL, deja solo las no leídas', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Ya leída'));
    $ana->notifications()->first()->markAsRead();
    $ana->notify(new PageNotice('Pendiente'));

    Livewire::withQueryParams(['filtro' => 'sin-leer'])->actingAs($ana)->test('core::pages.notifications')
        ->assertSee('Pendiente')
        ->assertDontSee('Ya leída')
        ->set('filter', 'todas')
        ->assertSee('Ya leída');
});

it('sin notificaciones, o sin ninguna sin leer, lo dice', function () {
    $ana = User::factory()->create();

    Livewire::actingAs($ana)->test('core::pages.notifications')->assertSee('Sin notificaciones');

    $ana->notify(new PageNotice('Leída'));
    $ana->notifications()->first()->markAsRead();

    Livewire::actingAs($ana)->test('core::pages.notifications')
        ->set('filter', 'sin-leer')
        ->assertSee('No tienes notificaciones sin leer');
});

it('marca todas como leídas, también las de otras páginas, y no toca las de otros', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();

    foreach (range(1, 30) as $number) {
        $ana->notify(new PageNotice("Aviso {$number}"));
    }
    $beto->notify(new PageNotice('De Beto'));

    Livewire::actingAs($ana)->test('core::pages.notifications')->call('markAllRead');

    expect($ana->unreadNotifications()->count())->toBe(0)
        ->and($beto->unreadNotifications()->count())->toBe(1);
});

it('abrir una la marca leída y lleva a su pantalla', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Aviso'));

    Livewire::actingAs($ana)->test('core::pages.notifications')
        ->call('open', $ana->notifications()->value('id'))
        ->assertRedirect(route('home'));

    expect($ana->unreadNotifications()->count())->toBe(0);
});

it('si su pantalla ya no está disponible, lo avisa y se queda', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Aviso', 'pantalla-que-no-existe'));

    $component = Livewire::actingAs($ana)->test('core::pages.notifications')
        ->call('open', $ana->notifications()->value('id'))
        ->assertNoRedirect();

    expect(lastToastOf($component))->toMatchArray(['type' => 'warning', 'title' => 'Esa pantalla no está disponible.']);
});

it('no abre la notificación de otro usuario', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $beto->notify(new PageNotice('De Beto'));

    $component = Livewire::actingAs($ana)->test('core::pages.notifications')
        ->call('open', $beto->notifications()->value('id'))
        ->assertNoRedirect();

    expect(lastToastOf($component)['title'])->toBe('Esa notificación no es tuya.')
        ->and($beto->unreadNotifications()->count())->toBe(1);
});

it('un filtro desconocido en la URL vuelve a «Todas»', function () {
    $ana = User::factory()->create();

    Livewire::actingAs($ana)->withQueryParams(['filtro' => 'SIN-LEER'])->test('core::pages.notifications')
        ->assertSet('filter', 'todas');
});

it('si al leer con «Sin leer» la página queda después de la última, vuelve a la última', function () {
    $ana = User::factory()->create();

    foreach (range(1, 26) as $number) {
        $ana->notify(new class($number) extends NexusNotification
        {
            public function __construct(private readonly int $number) {}

            public function message(object $notifiable): NotificationMessage
            {
                return new NotificationMessage(title: "Aviso {$this->number}", body: 'Detalle', icon: 'o-bell', route: 'home');
            }
        });
    }

    $component = Livewire::actingAs($ana)->withQueryParams(['filtro' => 'sin-leer', 'page' => 2])->test('core::pages.notifications');
    $ana->unreadNotifications()->latest()->first()->markAsRead();

    $component->call('refreshNotifications')->assertSee('Aviso')->assertSet('paginators.page', 1);
});

it('abrir o marcar todas avisa a la campana para que se vuelva a pintar', function () {
    $ana = User::factory()->create();

    Livewire::actingAs($ana)->test('core::pages.notifications')
        ->call('markAllRead')
        ->assertDispatched('notifications-changed');
});

it('agrupa por día: Hoy, Ayer, Esta semana y Antes, con su encabezado', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'America/Ciudad_Juarez'));
    $ana = User::factory()->create();

    foreach (['De hoy' => '2026-11-04 09:00', 'De ayer' => '2026-11-03 09:00', 'De esta semana' => '2026-11-02 09:00', 'Vieja' => '2026-10-20 09:00'] as $title => $at) {
        $ana->notify(new PageNotice($title));
        $ana->notifications()->where('data->title', $title)->update(['created_at' => CarbonImmutable::parse($at, 'America/Ciudad_Juarez')]);
    }

    Livewire::actingAs($ana)->test('core::pages.notifications')
        ->assertSeeHtmlInOrder(['data-testid="group-hoy"', 'De hoy', 'data-testid="group-ayer"', 'De ayer', 'data-testid="group-semana"', 'De esta semana', 'data-testid="group-antes"', 'Vieja'])
        ->assertSeeText('Esta semana')
        ->assertSeeText('Antes');
});

it('la tarjeta de la campana agrupa igual', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('De hoy'));

    Livewire::actingAs($ana)->test('core::components.bell')->assertSeeHtml('data-testid="group-hoy"');
});

it('el campus del aviso va al frente solo cuando el usuario trabaja en más de un campus', function () {
    Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $admin = User::factory()->admin()->create();
    $admin->notify(new PageNotice('En el dos', 'home', $c2->getKey()));

    Livewire::actingAs($admin)->test('core::pages.notifications')
        ->assertSeeHtml('data-testid="notification-campus"')
        ->assertSeeText('C2');
});

it('con un solo campus, el aviso no lleva la clave del campus', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $admin = User::factory()->admin()->create();
    $admin->notify(new PageNotice('En el dos', 'home', $c2->getKey()));

    Livewire::actingAs($admin)->test('core::pages.notifications')
        ->assertSee('En el dos')
        ->assertDontSeeHtml('data-testid="notification-campus"');
});

it('filtra por tipo, en la URL, y un tipo desconocido vuelve a todos', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Uno'));
    $ana->notify(new OtherPageNotice('Dos'));

    Livewire::withQueryParams(['tipo' => 'page-notice'])->actingAs($ana)->test('core::pages.notifications')
        ->assertSee('Uno')->assertDontSee('Dos')
        ->assertSeeText('Aviso de prueba')->assertSeeText('Otro aviso')
        ->set('type', 'other-page-notice')
        ->assertSee('Dos')->assertDontSee('Uno')
        ->set('type', 'no-existe')
        ->assertSet('type', '')
        ->assertSee('Uno')->assertSee('Dos');

    Livewire::withQueryParams(['tipo' => 'basura'])->actingAs($ana)->test('core::pages.notifications')
        ->assertSet('type', '');
});

it('los contadores van sin paréntesis: «Todas 2» y «Sin leer 1»', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Uno'));
    $ana->notify(new PageNotice('Dos'));
    $ana->notifications()->first()->markAsRead();

    Livewire::actingAs($ana)->test('core::pages.notifications')
        ->assertSeeInOrder(['Todas', '2', 'Sin leer', '1'])
        ->assertDontSeeText('(2)')
        ->assertDontSeeText('(1)');
});

it('la lista de la página tiene un ancho legible', function () {
    Livewire::actingAs(User::factory()->create())->test('core::pages.notifications')
        ->assertSeeHtml('data-testid="notifications-list"')
        ->assertSeeHtml('max-w-3xl');
});

it('los contadores cuentan solo el tipo elegido', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Uno'));
    $ana->notify(new PageNotice('Uno bis'));
    $ana->notify(new OtherPageNotice('Dos'));
    $ana->notifications()->where('data->title', 'Uno')->first()->markAsRead();

    $component = Livewire::actingAs($ana)->withQueryParams(['tipo' => 'page-notice'])->test('core::pages.notifications');

    expect($component->viewData('total'))->toBe(2)
        ->and($component->viewData('unread'))->toBe(1);
});

it('con un tipo elegido, marcar todas como leídas solo marca ese tipo y se deshabilita si no hay sin leer', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Uno'));
    $ana->notify(new OtherPageNotice('Dos'));

    Livewire::actingAs($ana)->withQueryParams(['tipo' => 'page-notice'])->test('core::pages.notifications')
        ->call('markAllRead')
        ->assertSeeHtml('data-testid="mark-all-read"')
        ->assertSeeHtmlInOrder(['data-testid="mark-all-read"', 'disabled']);

    expect($ana->unreadNotifications()->count())->toBe(1)
        ->and($ana->unreadNotifications()->first()->data['title'])->toBe('Dos');
});

it('las claves de tipo son únicas aunque dos clases compartan el nombre corto', function () {
    eval('namespace AlphaNs; final class SameName extends \\App\\Modules\\Core\\Notifications\\NexusNotification { public function message(object $n): \\App\\Modules\\Core\\Notifications\\NotificationMessage { return new \\App\\Modules\\Core\\Notifications\\NotificationMessage(title: "A", body: "b", icon: "o-bell", route: "home"); } }');
    eval('namespace BetaNs; final class SameName extends \\App\\Modules\\Core\\Notifications\\NexusNotification { public function message(object $n): \\App\\Modules\\Core\\Notifications\\NotificationMessage { return new \\App\\Modules\\Core\\Notifications\\NotificationMessage(title: "B", body: "b", icon: "o-bell", route: "home"); } }');

    $ana = User::factory()->create();
    $ana->notify(new SameName);
    $ana->notify(new BetaNs\SameName);

    expect(SameName::typeKey())->not->toBe(BetaNs\SameName::typeKey());

    Livewire::actingAs($ana)->withQueryParams(['tipo' => SameName::typeKey()])->test('core::pages.notifications')
        ->assertSee('A')
        ->assertViewHas('total', 1);
});

it('un tipo que ya no existe se normaliza también al pintar', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Uno'));

    $component = Livewire::actingAs($ana)->withQueryParams(['tipo' => 'page-notice'])->test('core::pages.notifications');
    $ana->notifications()->delete();
    $ana->notify(new OtherPageNotice('Dos'));

    $component->call('$refresh')->assertSet('type', '')->assertSee('Dos');
});

it('«Esta semana» empieza en lunes sin importar el locale de Carbon', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00', 'America/Ciudad_Juarez'));
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Domingo'));
    $ana->notifications()->update(['created_at' => CarbonImmutable::parse('2026-11-01 09:00', 'America/Ciudad_Juarez')]);

    $previous = CarbonImmutable::getLocale();
    CarbonImmutable::setLocale('en_US');

    try {
        Livewire::actingAs($ana)->test('core::pages.notifications')
            ->assertSeeHtml('data-testid="group-antes"')
            ->assertDontSeeHtml('data-testid="group-semana"');
    } finally {
        CarbonImmutable::setLocale($previous);
    }
});

it('un aviso de la noche del día anterior en UTC sigue siendo de hoy en Ciudad Juárez', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 03:00', 'UTC'));
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('De la tarde'));
    $ana->notifications()->update(['created_at' => CarbonImmutable::parse('2026-11-05 02:00', 'UTC')]);

    Livewire::actingAs($ana)->test('core::pages.notifications')->assertSeeHtml('data-testid="group-hoy"');
});

it('cada grupo y cada renglón conservan su separador', function () {
    $ana = User::factory()->create();
    $ana->notify(new PageNotice('Uno'));

    Livewire::actingAs($ana)->test('core::pages.notifications')
        ->assertSeeHtml('class="border-b border-base-300 last:border-b-0" data-testid="group-hoy"');
});
