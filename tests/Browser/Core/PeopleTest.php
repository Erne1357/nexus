<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;

/*
| «Personas y puestos» en el navegador: el admin nombra coordinadora a una persona desde su ficha y luego termina el
| nombramiento; un coordinador no ve la opción en el menú. La ficha completa se captura a 1440 y 390 px.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    $this->systems = Department::factory()->create(['name' => 'Sistemas y Computación']);

    $this->admin = User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    $this->rosa = User::factory()->create(['username' => '19110050', 'name' => 'Rosa Ruiz Almanza']);
    User::factory()->create(['username' => '19110051', 'name' => 'Zacarías Zúñiga']);
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])
        ->create(['username' => '19110001', 'name' => 'Carlos Coordinador']);
});

function signInToPeople(string $username)
{
    return visit('/login')
        ->fill('username', $username)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false');
}

it('el admin nombra coordinadora a una persona y luego termina el nombramiento', function () {
    $page = signInToPeople('admin')
        ->click('@hare')
        ->click('#nexus-menu a[data-label="Personas y puestos"]')
        ->assertPathIs('/people')
        ->assertSee('Zacarías Zúñiga')
        ->fill('search', 'Rosa')
        ->assertDontSee('Zacarías Zúñiga')
        ->click('@view-19110050')
        ->assertSee('usuario')
        ->assertSee('Sin puesto vigente')
        ->click('@appoint')
        ->assertSee('Nombrar a Rosa Ruiz Almanza')
        ->click('input[name="campus"][value="'.$this->c2->id.'"]')
        ->assertSee('Todas las de Presencial en Campus II')
        ->click('@career-ISC')
        ->fill('from', today()->subDays(10)->toDateString())
        ->click('@appoint-submit')
        ->assertSee('Nombramiento creado')
        ->assertSee('Coordinador de carrera · ISC · Presencial · Campus II')
        ->assertSee('Con 1 puesto vigente')
        ->assertNoJavaScriptErrors();

    expect(RoleAssignment::where('user_id', $this->rosa->id)->sole())
        ->role->toBe(Role::Coordinador)
        ->career_id->toBe($this->isc->id);

    $page->press('Terminar…')
        ->fill('lastDay', today()->subDay()->toDateString())
        ->click('@end-submit')
        ->assertSee('Nombramiento terminado')
        ->assertSee('Terminado por Ana Admin')
        ->assertSee('Sin puesto vigente')
        ->assertNoJavaScriptErrors();

    expect(RoleAssignment::where('user_id', $this->rosa->id)->sole()->valid_to->toDateString())->toBe(today()->subDay()->toDateString());
});

it('el admin nombra a un jefe de departamento sin registro de docente y lo termina', function () {
    $page = signInToPeople('admin')
        ->navigate('/people/'.$this->rosa->id)
        ->click('@appoint')
        ->assertSee('Nombrar a Rosa Ruiz Almanza')
        ->select('role', 'jefe_departamento')
        ->assertSee('Este puesto pide el departamento académico o Servicios Escolares. Tiene un solo jefe vigente.')
        ->select('unit', $this->systems->id)
        ->fill('from', today()->subDays(10)->toDateString())
        ->click('@appoint-submit')
        ->assertSee('Nombramiento creado')
        ->assertSee('Jefe de departamento')
        ->assertNoJavaScriptErrors();

    expect(RoleAssignment::where('user_id', $this->rosa->id)->sole())
        ->role->toBe(Role::JefeDepartamento)
        ->org_unit_id->toBe($this->systems->id);

    $page->press('Terminar…')
        ->fill('lastDay', today()->toDateString())
        ->click('@end-submit')
        ->assertSee('Nombramiento terminado')
        ->assertNoJavaScriptErrors();

    expect(RoleAssignment::where('user_id', $this->rosa->id)->sole()->valid_to->toDateString())->toBe(today()->toDateString());
});

it('un coordinador no ve «Personas y puestos» en el menú', function () {
    signInToPeople('19110001')
        ->assertScript('document.querySelector(\'#nexus-menu a[data-label="Personas y puestos"]\') === null')
        ->assertNoJavaScriptErrors();
});

it('la ficha se ve completa en escritorio y en celular, sin desbordar', function () {
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Docente, 'valid_from' => '2015-08-17']);
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_from' => today()->subMonths(3)->toDateString(), 'valid_to' => today()->addMonths(9)->toDateString()]);
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::JefeProyectoDocente, 'campus_id' => $this->c2->id, 'org_unit_id' => $this->systems->id, 'valid_from' => '2023-08-17', 'valid_to' => '2026-07-31']);
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Admin, 'valid_from' => today()->addMonths(2)->toDateString()]);
    activity()->performedOn($this->rosa)->causedBy($this->admin)->event('created')->withProperties(['role_assignment_id' => RoleAssignment::first()->id])->log('Nombró a Rosa Ruiz Almanza como Docente');

    $page = signInToPeople('admin')->navigate('/people/'.$this->rosa->id)->assertSee('Línea de vigencias');

    $page->resize(1440, 900)->assertSee('Nombramientos')->screenshot(filename: 'people-record-1440');
    $page->click('@appoint')->assertSee('Nombrar a Rosa Ruiz Almanza')->screenshot(filename: 'people-appoint-1440');
    $page->press('Cancelar');

    $page->resize(390, 844)->assertSee('Nombramientos')
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth')
        ->screenshot(filename: 'people-record-390')
        ->assertNoJavaScriptErrors();
});
