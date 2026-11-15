<?php

use App\Modules\Core\Models\MailLog;
use App\Modules\Core\Models\User;

/*
| Pantallas del grupo «Configuración» en el navegador (1.22-D y G): lo que solo se ve con el JavaScript corriendo. En
| «Bitácora», el botón «Detalle» de un renglón abre el cajón sin errores (antes, su `@click.stop` sin valor llegaba a
| Alpine como la expresión «@click.stop»). En «Correos y colas», cambiar de pestaña cambia la tabla sin errores (antes,
| Livewire reusaba la tabla de una pestaña para la otra y la de MaryUI arrancaba sin sus encabezados).
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

function signInToConfiguration()
{
    return visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('CF-S5: «Detalle» abre el cajón de una entrada de la bitácora sin errores de JavaScript', function () {
    $rosa = User::factory()->create(['username' => 'RURR800101AB1', 'name' => 'Rosa Ruiz Rivas']);
    activity()->performedOn($rosa)->causedBy($this->admin)->event('updated')
        ->withChanges(['attributes' => ['name' => 'Rosa Ruiz Rivas'], 'old' => ['name' => 'Rosa Ruiz']])
        ->log('Cambió los datos de Rosa Ruiz Rivas');

    signInToConfiguration()
        ->navigate('/configuracion/bitacora')
        ->assertSee('Cambió los datos de Rosa Ruiz Rivas')
        ->click('[data-testid^="detail-"]')
        ->assertSeeIn('@activity-drawer', 'Cambió los datos de Rosa Ruiz Rivas')
        ->assertSeeIn('@activity-changes', 'Rosa Ruiz Rivas')
        ->assertNoJavaScriptErrors();
});

it('CF-S8: las tres pestañas de «Correos y colas» cambian sin errores de JavaScript', function () {
    MailLog::factory()->create(['to' => 'rebeca.lopez@cdjuarez.tecnm.mx', 'subject' => 'Tu acceso a Nexus']);
    User::factory()->create([
        'username' => 'LOPR850312KT4',
        'name' => 'Rebeca López Ponce',
        'email' => 'rebeca.lopez@'.config('nexus.institutional_email_domain'),
        'password' => null,
        'access_invited_at' => now(),
        'access_invited_by' => $this->admin->id,
    ]);

    signInToConfiguration()
        ->navigate('/configuracion/correos-y-colas')
        ->assertSeeIn('[data-testid="mail-table"] table', 'Tu acceso a Nexus')
        ->click('@tab-invitations')
        ->assertSeeIn('[data-testid="invitations-table"] table', 'LOPR850312KT4')
        ->click('@tab-queues')
        ->assertPresent('@failed-table')
        ->click('@tab-mail')
        ->assertSeeIn('[data-testid="mail-table"] table', 'Tu acceso a Nexus')
        ->assertNoJavaScriptErrors();
});
