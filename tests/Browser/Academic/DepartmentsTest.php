<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;

/*
| CRUD de Departamentos en el navegador: el admin entra por el menú, da de alta un departamento y
| lo edita desde la UI. Departamentos es del área Académico, la del inicio.
*/

beforeEach(function () {
    Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
});

it('el admin da de alta un departamento y luego lo edita desde la UI', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('#nexus-menu a[data-label="Departamentos"]')
        ->assertPathIs('/academic/departments')
        ->assertSee('Sistemas y Computación')
        ->press('Nuevo departamento')
        ->fill('code', 'ind')
        ->fill('name', 'Industrial')
        ->press('Guardar')
        ->waitForText('Departamento creado')
        ->assertSee('Industrial')
        ->assertNoJavaScriptErrors();

    expect(Department::where('code', 'IND')->sole()->name)->toBe('Industrial');

    $page->click('@edit-IND')
        ->assertValue('code', 'IND')
        ->fill('name', 'Ingeniería Industrial')
        ->press('Guardar')
        ->waitForText('Departamento actualizado')
        ->assertSee('Ingeniería Industrial')
        ->assertNoJavaScriptErrors();

    expect(Department::where('code', 'IND')->sole()->name)->toBe('Ingeniería Industrial');
});
