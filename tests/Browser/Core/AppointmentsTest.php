<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;

/*
| «Nombrar» como cajón con casillas (T13, U3 opción A) en el navegador: el admin nombra a un coordinador sobre dos
| carreras de Presencial sin elegir campus y las ve en la pestaña «Nombramientos» con «Todos los campus». El cajón y la
| pestaña se capturan a 1440 y 390 px.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $industrial = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $systems->id]);
    $this->iind = Career::factory()->create(['code' => 'IIND', 'name' => 'Ingeniería Industrial', 'department_id' => $industrial->id]);

    foreach ([$this->isc, $this->iind] as $career) {
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $this->c2->id]);
    }

    User::factory()->admin()->create(['username' => 'admin', 'name' => 'Ana Admin']);
    $this->rosa = User::factory()->create(['username' => '19110050', 'name' => 'Rosa Ruiz Almanza']);
});

it('el admin nombra a un coordinador sobre dos carreras de Presencial sin campus', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/people/'.$this->rosa->id)
        ->click('@appoint')
        ->assertSee('Nombrar a Rosa Ruiz Almanza')
        ->assertSee('Todas las de Presencial')
        ->resize(1440, 900)
        ->screenshot(filename: 'appoint-drawer-1440')
        ->click('@career-ISC')
        ->click('@career-IIND')
        ->assertSee('Nombrar en 2 carreras')
        ->click('@appoint-submit')
        ->assertSee('Nombramientos creados')
        ->assertSee('Con 2 puestos vigentes')
        ->assertNoJavaScriptErrors();

    $assignments = RoleAssignment::where('user_id', $this->rosa->id)->get();

    expect($assignments)->toHaveCount(2)
        ->and($assignments->pluck('role')->unique()->all())->toBe([Role::Coordinador])
        ->and($assignments->pluck('campus_id')->filter()->all())->toBe([])
        ->and($assignments->pluck('career_id')->sort()->values()->all())->toBe(collect([$this->isc->id, $this->iind->id])->sort()->values()->all());

    $page->navigate('/people')
        ->click('@tab-appointments')
        ->assertSee('Carrera o departamento')
        ->assertSee('Todos los campus')
        ->assertSee('Presencial')
        ->resize(1440, 900)
        ->screenshot(filename: 'appointments-tab-1440')
        ->resize(390, 844)
        ->screenshot(filename: 'appointments-tab-390')
        ->assertNoJavaScriptErrors();
});

it('el cajón de «Nombrar» se ve completo en celular, sin desbordar', function () {
    $page = visit('/login')
        ->fill('username', 'admin')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/people/'.$this->rosa->id)
        ->resize(390, 844)
        ->click('@appoint')
        ->assertSee('Nombrar a Rosa Ruiz Almanza')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'appoint-drawer-390')
        ->assertNoJavaScriptErrors();
});
