<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| Catálogo de Periodos en el navegador: el admin da de alta un periodo, lo edita y lo marca como
| actual desde la UI; un coordinador no ve la opción en el menú.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToTerms(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('el admin da de alta un periodo, lo edita y lo marca como actual desde la UI', function () {
    $page = signInToTerms('admin')
        ->click('#nexus-menu a[data-label="Periodos"]')
        ->assertPathIs('/terms')
        ->press('Nuevo periodo')
        ->fill('code', '20271')
        ->fill('starts_on', '2027-01-18')
        ->fill('ends_on', '2027-06-11')
        ->wait(1)
        ->assertValue('name', 'Ene-Jun 2027')
        ->press('Guardar')
        ->waitForText('Periodo creado')
        ->assertSee('Ene-Jun 2027')
        ->assertNoJavaScriptErrors();

    expect(Term::where('code', '20271')->sole()->is_current)->toBeFalse();

    $page->click('@edit-20271')
        ->assertValue('code', '20271')
        ->fill('name', 'Enero a Junio 2027')
        ->press('Guardar')
        ->waitForText('Periodo actualizado')
        ->assertSee('Enero a Junio 2027')
        ->assertNoJavaScriptErrors();

    expect(Term::where('code', '20271')->sole()->name)->toBe('Enero a Junio 2027');

    // Playwright descarta los diálogos: se acepta el wire:confirm a mano.
    $page->script('window.confirm = () => true');

    $page->click('table [aria-label="Acciones de 20271"]')
        ->click('@make-current-20271')
        ->wait(1)
        ->assertPresent('table .badge-success')
        ->assertNoJavaScriptErrors();

    expect(Term::where('code', '20271')->sole()->is_current)->toBeTrue();
});

it('un coordinador no ve la opción Periodos en el menú', function () {
    signInToTerms('19110001')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Periodos')
        ->click('@hare')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Periodos')
        ->assertNoJavaScriptErrors();
});
