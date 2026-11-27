<?php

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
| La campana de la banda (tarea 2.9, opción A elegida el 2 de noviembre de 2026): globo con las no leídas,
| tarjeta con las recientes del usuario y «Marcar todas como leídas». Abrir una lleva a su pantalla y, si ya
| no está disponible, lo avisa sin llevarlo.
*/

final class BellNotice extends NexusNotification
{
    public function __construct(private readonly string $title, private readonly ?string $route = 'home') {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: $this->title, body: 'Detalle', icon: 'o-bell', route: $this->route);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', 'America/Ciudad_Juarez'));
});

it('está en la banda de toda pantalla con sesión, en diferido para no sumar consultas a la pantalla', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertOk()
        ->assertSeeHtml('data-testid="bell-placeholder"')
        ->assertDontSeeHtml('data-testid="bell"');
});

it('muestra cuántas no ha leído y las recientes del usuario, sin las de otros', function () {
    $ana = User::factory()->create();
    $ana->notify(new BellNotice('Tienes catálogos por validar'));
    $ana->notify(new BellNotice('Ya leída'));
    $ana->notifications()->where('data->title', 'Ya leída')->first()->markAsRead();
    User::factory()->create()->notify(new BellNotice('Aviso de Beto'));
    $this->travel(5)->minutes();

    Livewire::actingAs($ana)->test('core::components.bell')
        ->assertSeeHtml('aria-label="Notificaciones: 1 sin leer"')
        ->assertSeeHtml('data-testid="bell-count"')
        ->assertSee('Tienes catálogos por validar')
        ->assertSee('Ya leída')
        ->assertDontSee('Aviso de Beto')
        ->assertSee('hace 5 minutos');
});

it('sin notificaciones no muestra globo y dice que no hay', function () {
    Livewire::actingAs(User::factory()->create())->test('core::components.bell')
        ->assertSeeHtml('aria-label="Notificaciones: sin notificaciones nuevas"')
        ->assertDontSeeHtml('data-testid="bell-count"')
        ->assertSee('Sin notificaciones');
});

it('con más de 99 sin leer, el globo dice 99+', function () {
    $ana = User::factory()->create();

    foreach (range(1, 100) as $number) {
        $ana->notify(new BellNotice("Aviso {$number}"));
    }

    Livewire::actingAs($ana)->test('core::components.bell')
        ->assertSeeHtml('aria-label="Notificaciones: más de 99 sin leer"')
        ->assertSee('99+');
});

it('abrir una la marca leída y lleva a su pantalla', function () {
    $ana = User::factory()->create();
    $ana->notify(new BellNotice('Aviso'));

    Livewire::actingAs($ana)->test('core::components.bell')
        ->call('open', $ana->notifications()->value('id'))
        ->assertRedirect(route('home'));

    expect($ana->unreadNotifications()->count())->toBe(0);
});

it('si su pantalla ya no está disponible, lo avisa y se queda', function () {
    $ana = User::factory()->create();
    $ana->notify(new BellNotice('Aviso', 'pantalla-que-no-existe'));

    $component = Livewire::actingAs($ana)->test('core::components.bell')
        ->call('open', $ana->notifications()->value('id'))
        ->assertNoRedirect();

    expect(lastToastOf($component))->toMatchArray(['type' => 'warning', 'title' => 'Esa pantalla no está disponible.']);
});

it('no abre la notificación de otro usuario', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $beto->notify(new BellNotice('De Beto'));

    $component = Livewire::actingAs($ana)->test('core::components.bell')
        ->call('open', $beto->notifications()->value('id'))
        ->assertNoRedirect();

    expect(lastToastOf($component)['title'])->toBe('Esa notificación no es tuya.')
        ->and($beto->unreadNotifications()->count())->toBe(1);
});

it('marca todas como leídas', function () {
    $ana = User::factory()->create();
    $ana->notify(new BellNotice('Uno'));
    $ana->notify(new BellNotice('Dos'));

    Livewire::actingAs($ana)->test('core::components.bell')
        ->call('markAllRead')
        ->assertSeeHtml('aria-label="Notificaciones: sin notificaciones nuevas"');

    expect($ana->unreadNotifications()->count())->toBe(0);
});

it('al pie de la tarjeta, «Ver todas» dice cuántas notificaciones tiene en total aunque la tarjeta muestre solo las recientes', function () {
    $ana = User::factory()->create();

    foreach (range(1, 20) as $number) {
        $ana->notify(new BellNotice("Aviso {$number}"));
    }

    Livewire::actingAs($ana)->test('core::components.bell')
        ->assertSeeHtml('data-testid="notification"')
        ->assertSeeHtml('href="'.route('core.notifications').'"')
        ->assertSee('Ver todas (20)');
});

it('sin notificaciones no ofrece «Ver todas»', function () {
    Livewire::actingAs(User::factory()->create())->test('core::components.bell')
        ->assertDontSee('Ver todas');
});
