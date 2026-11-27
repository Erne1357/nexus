<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| Un 419 de Livewire (sesión, CSRF o perfil vencidos) se avisa con el diálogo de Nexus y se ofrece recargar; nunca el
| `confirm()` en inglés de Livewire (ADR-0023; tarea 1.88). El servidor de pruebas no revisa el CSRF, así que el 419 se
| simula en el navegador: `fetch` responde 419 a la petición de Livewire, igual que lo haría el servidor con la sesión
| vencida. `confirm` se reemplaza para contar los diálogos nativos que se abrirían.
*/

beforeEach(function () {
    Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

function expireNextLivewireRequest(array $headers = []): string
{
    $headers = json_encode($headers, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            window.__nativeDialogs = 0;
            window.confirm = () => { window.__nativeDialogs++; return false; };
            window.alert = () => { window.__nativeDialogs++; };

            const realFetch = window.fetch.bind(window);
            window.fetch = (input, init) => {
                const url = typeof input === 'string' ? input : (input?.url ?? '');

                return url.includes('/livewire') && url.includes('update')
                    ? Promise.resolve(new Response('', { status: 419, headers: {$headers} }))
                    : realFetch(input, init);
            };

            const root = document.querySelector('[wire\\\\:id]');
            window.Livewire.find(root.getAttribute('wire:id')).\$wire.\$refresh();
        })()
    JS;
}

function signInForPageExpired()
{
    return visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('un 419 sin cabecera se avisa en español con el diálogo de Nexus y no con el confirm() de Livewire', function () {
    $page = signInForPageExpired();
    $page->script(expireNextLivewireRequest());

    $page->assertSee('La página venció')
        ->assertSee('Tu sesión cambió o expiró')
        ->assertSee('Recargar')
        ->assertScript('window.__nativeDialogs', 0)
        ->assertNoJavaScriptErrors();
});

it('un 419 con la cabecera del cambio de perfil dice que el perfil cambió', function () {
    $page = signInForPageExpired();
    $page->script(expireNextLivewireRequest(['X-Nexus-Role-Changed' => '1']));

    $page->assertSee('Tu perfil cambió')
        ->assertDontSee('La página venció')
        ->assertScript('window.__nativeDialogs', 0)
        ->assertNoJavaScriptErrors();
});
