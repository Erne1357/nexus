<?php

use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| CRUD de Campus en el navegador: el admin da de alta un campus y lo edita desde la UI; un
| coordinador no ve la opción en el menú (criterio 2 de la fase 0).
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToCampuses(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/');
}

it('el admin da de alta un campus y luego lo edita desde la UI', function () {
    $page = signInToCampuses('admin')
        ->click('Campus')
        ->assertPathIs('/campus')
        ->assertSee('Campus II')
        ->press('Nuevo campus')
        ->fill('code', 'c3')
        ->fill('name', 'Campus III')
        ->select('modality', 'mixta')
        ->press('Guardar')
        ->waitForText('Campus creado')
        ->assertSee('Campus III')
        ->assertNoJavaScriptErrors();

    expect(Campus::where('code', 'C3')->sole()->modality)->toBe(Modality::Mixta);

    $page->click('@edit-C3')
        ->assertValue('code', 'C3')
        ->fill('name', 'Campus Tres')
        ->press('Guardar')
        ->waitForText('Campus actualizado')
        ->assertSee('Campus Tres')
        ->assertNoJavaScriptErrors();

    expect(Campus::where('code', 'C3')->sole()->name)->toBe('Campus Tres');
});

it('un coordinador no ve la opción Campus en el menú', function () {
    signInToCampuses('19110001')
        ->assertSeeIn('[aria-label="Navegación principal"]', 'Inicio')
        ->assertDontSeeIn('[aria-label="Navegación principal"]', 'Campus')
        ->assertNoJavaScriptErrors();
});
