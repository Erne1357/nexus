<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;

/*
| Docentes en el navegador: el jefe de Sistemas y Computación (de todo el Tec) entra por el icono de Recursos
| humanos, da de alta un docente (con su departamento, sin elegirlo) y lo ve en su lista; al escribir el número de
| uno que ya existe lo adscribe a su departamento (S9). A 1440 px la búsqueda, los filtros y la acción caben en un
| renglón sin cortar ningún texto. El coordinador no ve el área.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);

    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create(['username' => 'jefe.sc', 'name' => 'Julia Jefa']);
});

it('el jefe de SC da de alta un docente y lo ve en su lista', function () {
    visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->click('[data-menu-area="recursos_humanos"] [data-menu-flip="icon"]')
        ->assertPathIs('/faculty/teachers')
        ->assertSee('Ana Pérez')
        ->press('Nuevo docente')
        ->assertSee('Das de alta docentes solo en este departamento.')
        ->assertScript("document.querySelector('select[name=department_id]').selectedOptions[0].text", 'SC · Sistemas y Computación')
        ->assertScript("document.querySelector('select[name=department_id]').disabled", true)
        ->fill('employee_number', '2000010')
        ->fill('name', 'Gabriel Mora')
        ->press('Guardar')
        ->waitForText('Docente creado')
        ->assertSee('Gabriel Mora')
        ->assertNoJavaScriptErrors();

    expect(Teacher::where('employee_number', '2000010')->sole()->adscriptions()->sole()->department_id)->toBe($this->sc->id);
});

it('al escribir el número de un docente que ya existe, el jefe lo adscribe a su departamento sin darlo de alta otra vez (S9)', function () {
    $industrial = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $existing = Teacher::factory()->adscribedTo($industrial)->create(['employee_number' => '1000002', 'name' => 'Beto Ruiz']);

    visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers')
        ->assertDontSee('Beto Ruiz')
        ->press('Nuevo docente')
        ->fill('employee_number', '1000002')
        ->keys('input[name="employee_number"]', 'Tab')
        ->waitForText('Ya existe el docente 1000002 · Beto Ruiz')
        ->press('Adscribir a SC')
        ->waitForText('Docente adscrito a SC')
        ->assertSee('Beto Ruiz')
        ->assertNoJavaScriptErrors();

    expect($existing->adscriptions()->pluck('department_id')->all())->toContain($this->sc->id);
});

it('a 1440 px la búsqueda, los filtros y la acción caben en un renglón sin cortar textos, y «Más acciones» abre «Dar de alta usuarios»', function () {
    User::factory()->admin()->create(['username' => 'admin']);

    visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->resize(1440, 900)
        ->navigate('/faculty/teachers')
        ->assertScript(catalogToolbarInOneRowScript(), true)
        ->click('[data-testid="catalog-toolbar"] button[aria-label="Más acciones"]')
        ->assertVisible('[data-testid="catalog-toolbar"] [role="menuitem"]')
        ->assertSeeIn('[data-testid="catalog-toolbar"] [role="menu"]', 'Dar de alta usuarios')
        ->assertNoJavaScriptErrors();
});

it('el coordinador no ve el área de Recursos humanos', function () {
    User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create(['username' => 'coord']);

    visit('/login')
        ->fill('username', 'coord')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertNotPresent('[data-menu-area="recursos_humanos"]')
        ->assertNoJavaScriptErrors();
});
