<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;

/*
| Las capas de la banda (DESIGN.md, "Capas"): la banda y sus tarjetas flotantes (campana y credencial) quedan
| por encima de lo fijo del contenido (encabezados `sticky z-20` de la bandeja, tabla de la propuesta, matriz de
| avance), y el menú abierto también. Se simula lo fijo del contenido con un velo `position: fixed` de z-20 que
| cubre toda la pantalla bajo la banda y se prueba qué elemento recibe el clic en el centro de cada tarjeta.
*/

final class LayersNotice extends NexusNotification
{
    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: 'Aviso de capas', body: 'Detalle', icon: 'o-bell', route: 'core.notifications');
    }
}

beforeEach(function () {
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);

    $admin = User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    $admin->notify(new LayersNotice);
});

/** Lo fijo del contenido: un velo de z-20 bajo la banda que atrapa cualquier clic que le llegue. */
function coverContentWithFixedLayer($page): void
{
    $page->script('(() => {
        const probe = document.createElement("div");
        probe.id = "fixed-content-probe";
        probe.style.cssText = "position:fixed;inset:67px 0 0 0;z-index:20;background:#f0f";
        document.body.append(probe);
    })()');
}

/** True si el elemento en el centro de `selector` es el mismo o está dentro de `selector`. */
function receivesClickAtCenter(string $selector): string
{
    return "(() => { const box = document.querySelector('{$selector}').getBoundingClientRect(); const hit = document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2); return !!hit && !!hit.closest('{$selector}'); })()";
}

it('la tarjeta de la campana queda por encima de lo fijo del contenido y un clic abre el aviso', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->assertVisible('@bell');

    coverContentWithFixedLayer($page);

    $page->click('@bell')
        ->assertAttribute('@bell', 'aria-expanded', 'true')
        ->assertScript(receivesClickAtCenter('[data-testid="notification"]'), true)
        ->click('@notification')
        ->assertPathIs('/notifications')
        ->assertNoJavaScriptErrors();
});

it('la tarjeta de la credencial queda por encima de lo fijo del contenido', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');

    coverContentWithFixedLayer($page);

    $page->click('@credential')
        ->assertAttribute('@credential', 'aria-expanded', 'true')
        ->assertScript(receivesClickAtCenter('#nexus-credential'), true)
        ->assertScript(receivesClickAtCenter('[data-testid="theme-toggle"]'), true)
        ->assertNoJavaScriptErrors();
});

it('el menú abierto y su velo quedan por encima de lo fijo del contenido', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');

    coverContentWithFixedLayer($page);

    $page->click('@hare')
        ->assertScript('document.body.dataset.menu', 'open')
        ->assertScript('Alpine.$data(document.body).busy === false')
        ->assertScript(receivesClickAtCenter('#nexus-menu-search'), true)
        ->assertNoJavaScriptErrors();
});
