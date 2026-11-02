<?php

use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Livewire\Livewire;

/*
| «Todas las notificaciones» (core.notifications): la lista completa del usuario, paginada, con el filtro
| Todas / Sin leer, «Marcar todas como leídas» y el mismo flujo de abrir que la campana (OpenNotification).
| Solo ve y abre las suyas.
*/

final class PageNotice extends NexusNotification
{
    public function __construct(private readonly string $title, private readonly ?string $route = 'home') {}

    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: $this->title, body: 'Detalle', icon: 'o-bell', route: $this->route);
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
