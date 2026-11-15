<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NexusNotification;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/*
| Abrir un aviso de la campana lleva a su pantalla con wire:navigate. Si la campana le pedía al servidor volver a
| pintarse (`notifications-changed`) y esa respuesta llegaba después del cambio de pantalla, Livewire la aplicaba a la
| campana vieja, que Alpine ya había limpiado, y la consola decía «Alpine Expression Error: bellOpen is not defined».
| En la demo pasaba cuando la pantalla del aviso respondía antes que la campana; aquí lo fuerza un middleware que
| retrasa esa petición.
*/

final class BellOpensTerms extends NexusNotification
{
    public function message(object $notifiable): NotificationMessage
    {
        return new NotificationMessage(title: 'Aviso de periodos', body: 'Detalle', icon: 'o-bell', route: 'core.terms');
    }
}

/** Retrasa la petición con la que un componente se vuelve a pintar por `notifications-changed`. */
final class DelayNotificationsChanged
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (str_ends_with($request->path(), '/update') && str_contains($request->getContent(), 'notifications-changed')) {
            usleep(700_000);
        }

        return $next($request);
    }
}

it('abrir un aviso de la campana lleva a su pantalla sin errores de Alpine en la consola', function () {
    app(Kernel::class)->pushMiddleware(DelayNotificationsChanged::class);
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    $admin = User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    $admin->notify(new BellOpensTerms);

    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertVisible('@bell');

    // Alpine avisa sus errores con console.warn, que la prueba de navegador no junta.
    $page->script('(() => { window.__alpineWarnings = []; const warn = console.warn; console.warn = (...args) => { window.__alpineWarnings.push(args.map(String).join(" ")); warn.apply(console, args); }; })()');

    $page->click('@bell')
        ->click('@notification')
        ->assertPathIs('/terms')
        ->wait(1.5);

    expect($page->script('window.__alpineWarnings'))->toBe([]);
    $page->assertNoJavaScriptErrors();
});

it('al volver con Atrás, la campana no trae como no leído el aviso que se abrió', function () {
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    $admin = User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    $admin->notify(new BellOpensTerms);

    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertSeeIn('@bell-count', '1');

    $page->click('@bell')
        ->click('@notification')
        ->assertPathIs('/terms')
        ->assertMissing('@bell-count');

    // wire:navigate repone Inicio de su caché, que se guardó antes de abrir el aviso.
    $page->back()->assertPathIs('/')->wait(1.5);

    expect($page->script('document.querySelector("[data-testid=bell-count]") === null'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
