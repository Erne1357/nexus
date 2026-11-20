<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;

/*
| Docentes en el navegador: el jefe de Sistemas y Computación (de todo el Tec) entra por el icono de Recursos
| humanos, da de alta un docente (con su departamento, sin elegirlo) y lo ve en su lista; con el número de uno que
| ya existe solo se le dice que está registrado, y la Subdirección es quien lo adscribe (S9). A 1440 px la búsqueda,
| los filtros y la acción caben en un renglón sin cortar ningún texto, y la tabla con los cuatro estados de acceso
| (AC-04) cabe sin desplazarse de lado. El jefe filtra a los que no tienen correo institucional y lo captura en
| «Editar docente» (AC-06). El coordinador no ve el área.
*/

beforeEach(function () {
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    Teacher::factory()->adscribedTo($this->sc)->create(['rfc' => 'PRUE000001AB1', 'name' => 'Ana Pérez']);

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
        ->fill('rfc', 'NUEV000010AB1')
        ->fill('name', 'Gabriel Mora')
        ->press('Guardar')
        ->waitForText('Docente creado')
        ->assertSee('Gabriel Mora')
        ->assertNoJavaScriptErrors();

    expect(Teacher::where('rfc', 'NUEV000010AB1')->sole()->adscriptions()->sole()->department_id)->toBe($this->sc->id);
});

it('al escribir el número de un docente que ya existe, al jefe solo le dice que está registrado, sin decir de quién (S9)', function () {
    $industrial = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    Teacher::factory()->adscribedTo($industrial)->create(['rfc' => 'PRUE000002AB1', 'name' => 'Beto Ruiz']);

    visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers')
        ->press('Nuevo docente')
        ->fill('rfc', 'PRUE000002AB1')
        ->keys('input[name="rfc"]', 'Tab')
        ->waitForText('Ese RFC ya está registrado. Pide a Subdirección que lo adscriba a tu departamento.')
        ->assertDontSee('Beto Ruiz')
        ->assertNoJavaScriptErrors();
});

it('la Subdirección escribe el número de un docente que ya existe y lo adscribe a un departamento sin darlo de alta otra vez (S9)', function () {
    $industrial = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $existing = Teacher::factory()->adscribedTo($industrial)->create(['rfc' => 'PRUE000002AB1', 'name' => 'Beto Ruiz']);
    User::factory()->withRole(Role::Subdireccion)->create(['username' => 'subdir']);

    visit('/login')
        ->fill('username', 'subdir')
        ->fill('password', 'password')
        ->press('Entrar')
        ->navigate('/faculty/teachers')
        ->press('Nuevo docente')
        ->select('department_id', $this->sc->id)
        ->fill('rfc', 'PRUE000002AB1')
        ->keys('input[name="rfc"]', 'Tab')
        ->waitForText('Ya existe el docente PRUE000002AB1 · Beto Ruiz')
        ->press('Asignar a SC')
        ->waitForText('Docente asignado a SC')
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

it('el jefe de SC filtra a los que no tienen correo institucional y lo captura en «Editar docente»; la tabla con los cuatro estados de acceso cabe a 1440 px y la página a 390 px', function () {
    foreach ([
        ['PRUE000003AB1', 'María Guadalupe Hernández Villalobos', ['must_change_password' => true]],
        ['PRUE000004AB1', 'Darío Soto', ['password' => null, 'access_invited_at' => now()]],
        ['PRUE000005AB1', 'Elsa Mora', ['must_change_password' => false]],
    ] as [$rfc, $name, $user]) {
        Teacher::factory()->adscribedTo($this->sc)->create([
            'rfc' => $rfc,
            'name' => $name,
            'institutional_email' => strtolower($rfc).'@cdjuarez.tecnm.mx',
            'user_id' => User::factory()->withRole(Role::Docente)->create(['username' => $rfc, ...$user])->id,
        ]);
    }

    $page = visit('/login')
        ->fill('username', 'jefe.sc')
        ->fill('password', 'password')
        ->press('Entrar')
        ->resize(1440, 900)
        ->navigate('/faculty/teachers')
        ->assertSeeIn('@missing-emails', '1 docente activo sin correo institucional')
        ->assertSee('Debe cambiar su contraseña')
        ->assertSee('Invitación enviada')
        ->assertSee('Con acceso')
        ->assertScript("(() => { const box = document.querySelector('table').parentElement; return box.scrollWidth <= box.clientWidth; })()", true)
        ->click('Ver solo ese')
        ->waitForText('Solo los que no tienen correo institucional.')
        ->assertDontSee('Elsa Mora')
        ->click('@edit-PRUE000001AB1')
        ->waitForText('A él va su invitación de acceso.')
        ->fill('institutional_email', 'ana.perez@cdjuarez.tecnm.mx')
        ->press('Guardar')
        ->waitForText('Docente actualizado')
        ->assertNoJavaScriptErrors();

    $page->resize(390, 844)
        ->navigate('/faculty/teachers')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();

    expect(Teacher::where('rfc', 'PRUE000001AB1')->sole()->institutional_email)->toBe('ana.perez@cdjuarez.tecnm.mx');
});
