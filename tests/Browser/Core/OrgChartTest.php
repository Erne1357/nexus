<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;

/*
| «Organigrama» en el navegador: el admin da de alta una unidad desde el panel, la mueve y la desactiva; un
| departamento académico se ve con candado y sus acciones deshabilitadas; en celular el diagrama es una lista con
| rieles, sin desbordar; un coordinador no ve la opción en el menú. El diagrama se captura a 1440 y 390 px.
*/

beforeEach(function () {
    $this->seed(OrgChartSeeder::class);

    $basics = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    Department::factory()->create(['code' => 'EE', 'name' => 'Eléctrica y Electrónica']);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);

    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->systems->id]);
    CareerCampus::factory()->create(['career_id' => $isc->id, 'campus_id' => $c2->id]);

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    User::factory()->withRole(Role::Subdireccion, ['valid_from' => '2025-09-01'])->create(['name' => 'Alejandro Rentería Cota']);
    User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $basics->id])->create(['name' => 'Elena Gutiérrez Parra']);
    User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->systems->id])->create(['name' => 'Jorge Luis Medrano Prieto']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $c2->id, 'career_id' => $isc->id])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToOrgChart(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('el admin crea, mueve y desactiva una unidad', function () {
    $page = signInToOrgChart('admin')
        ->click('#nexus-menu a[data-label="Organigrama"]')
        ->assertPathIs('/org-chart')
        ->resize(1440, 900)
        ->assertSee('Organigrama')
        ->assertSee('1 departamento académico sin jefe')
        ->screenshot(filename: 'org-chart-1440')
        ->click('@org-unit-SUB-PLANEACION')
        ->waitForText('Dirección › Subdirección de Planeación y Vinculación')
        ->click('@unit-add-child')
        ->waitForText('Nueva unidad')
        ->fill('name', 'Oficina de Becas')
        ->fill('code', 'BECAS')
        ->select('kind', 'oficina')
        ->click('@unit-form-submit')
        ->waitForText('Unidad creada')
        ->assertPresent('@org-unit-BECAS')
        ->assertNoJavaScriptErrors();

    $grants = OrgUnit::where('code', 'BECAS')->sole();
    $administrative = OrgUnit::where('code', 'SUB-ADMINISTRATIVA')->sole();

    expect($grants->parent_id)->toBe(OrgUnit::where('code', 'SUB-PLANEACION')->value('id'));

    $page->click('@org-unit-BECAS')
        ->click('@unit-move')
        ->waitForText('Mover unidad')
        ->select('parent', $administrative->id)
        ->click('@unit-form-submit')
        ->waitForText('Unidad movida')
        ->assertNoJavaScriptErrors();

    expect($grants->fresh()->parent_id)->toBe($administrative->id);

    $page->click('@unit-deactivate')
        ->click('@confirm-accept')
        ->waitForText('Unidad desactivada')
        ->assertMissing('@org-unit-BECAS')
        ->check('showInactive')
        ->assertPresent('@org-unit-BECAS')
        ->assertSeeIn('@org-unit-BECAS', 'Inactiva')
        ->assertNoJavaScriptErrors();

    expect($grants->fresh()->is_active)->toBeFalse();
});

it('un departamento académico se ve con candado y sus acciones deshabilitadas', function () {
    signInToOrgChart('admin')
        ->navigate('/org-chart')
        ->assertSeeIn('@org-unit-SC', 'Jorge Luis Medrano Prieto')
        ->assertSeeIn('@org-unit-EE', 'Sin jefe vigente')
        ->click('@org-unit-SC')
        ->waitForText('su nombre, su clave y su estado se editan en Administración › Departamentos.')
        ->assertScript("document.querySelector('[data-testid=org-unit-SC]').classList.contains('ring-2')")
        ->assertScript("document.querySelector('[data-testid=unit-rename]').disabled && document.querySelector('[data-testid=unit-move]').disabled && document.querySelector('[data-testid=unit-deactivate]').disabled")
        ->assertMissing('@unit-add-child')
        ->assertNoJavaScriptErrors();
});

it('el admin nombra al jefe de un departamento desde el panel y lo termina', function () {
    User::factory()->create(['username' => 'rosa', 'name' => 'Rosa Medina Torres']);

    $page = signInToOrgChart('admin')
        ->navigate('/org-chart')
        ->resize(1440, 900)
        ->click('@org-unit-EE')
        ->waitForText('Nadie tiene un puesto vigente aquí.')
        ->click('@unit-appoint')
        ->waitForText('Nombrar a alguien en Eléctrica y Electrónica')
        ->fill('findPerson', 'Rosa')
        ->waitForText('Rosa Medina Torres')
        ->click('@appoint-pick-rosa')
        ->waitForText('Cambiar')
        ->screenshot(filename: 'org-chart-appoint-form-1440')
        ->click('@appoint-submit')
        ->waitForText('Nombramiento creado')
        ->assertSeeIn('@unit-assignments', 'Rosa Medina Torres')
        ->assertNoJavaScriptErrors();

    expect(User::where('username', 'rosa')->sole()->roleAssignments()->sole())
        ->role->toBe(Role::JefeDepartamento)
        ->org_unit_id->toBe(OrgUnit::where('code', 'EE')->value('id'));

    $page->resize(1440, 900)
        ->screenshot(filename: 'org-chart-appoint-1440')
        ->click('[data-testid^="end-"]')
        ->waitForText('Terminar nombramiento')
        ->screenshot(filename: 'org-chart-end-box-1440')
        ->click('@end-submit')
        ->waitForText('Nombramiento terminado')
        ->assertNoJavaScriptErrors();

    $page->resize(390, 844)
        ->click('@unit-appoint')
        ->waitForText('Nombrar a alguien en Eléctrica y Electrónica')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'org-chart-appoint-form-390')
        ->click('dialog[open] button:has-text("Cancelar")')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'org-chart-panel-390')
        ->assertNoJavaScriptErrors();

    expect(User::where('username', 'rosa')->sole()->roleAssignments()->sole()->valid_to)->not->toBeNull();
});

it('en celular el diagrama es una lista con rieles', function () {
    $page = signInToOrgChart('admin')
        ->resize(390, 844)
        ->navigate('/org-chart')
        ->assertSee('Organigrama')
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=org-level-1]')).display !== 'flex'")
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=org-level-1]')).borderInlineStartWidth === '2px'")
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'org-chart-390');

    $page->click('@org-unit-SUB-ACADEMICA')
        ->waitForText('Ver en Personas y puestos')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

it('un coordinador no ve «Organigrama» en el menú', function () {
    signInToOrgChart('19110001')
        ->assertScript('document.querySelector(\'#nexus-menu a[data-label="Organigrama"]\') === null')
        ->assertNoJavaScriptErrors();
});
