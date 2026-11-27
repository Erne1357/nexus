<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;

/*
| El Inicio es el mapa del campus (tarea 1.24.1): `/` con nombre `home`, para todo el que tiene sesión (D6 del usuario,
| 20 de noviembre de 2026), dentro de la app (D5); lo que era el Inicio queda en «Mi espacio › Resumen» (`/resumen`).
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

it('sin sesión no se ve el mapa: manda al acceso', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

it('el Inicio es el mapa del campus para cada puesto, con sus datos embebidos', function (Role $role) {
    config(['nexus.campus_map_3d' => true]);
    $user = User::factory()->withRole($role, match ($role) {
        Role::JefeDepartamento => ['campus_id' => $this->campus->id, 'department_id' => departmentKey(10)],
        default => ['campus_id' => $this->campus->id],
    })->create();

    actingAsRole($user, $role)
        ->get('/')
        ->assertOk()
        ->assertSee('data-testid="campus-map"', false)
        ->assertSee('id="ubicatec-data"', false)
        ->assertSee('Mapa del campus');
})->with([Role::Docente, Role::JefeDepartamento, Role::Alumno]);

it('lo que era el Inicio queda como Resumen en Mi espacio', function () {
    $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create(['name' => 'Ana López']);

    actingAsRole($user, Role::Docente)
        ->get(route('core.summary'))
        ->assertOk()
        ->assertSee('Hola, Ana López');

    actingAsRole($user, Role::Docente)
        ->get('/')
        ->assertSee(route('core.summary'), false)
        ->assertSee('Resumen');
});

it('con la bandera en false no carga el visor 3D y muestra el marcador estático', function () {
    config(['nexus.campus_map_3d' => false]);
    $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    actingAsRole($user, Role::Docente)
        ->get('/')
        ->assertOk()
        ->assertDontSee('ubicatec/main.js', false)
        ->assertDontSee('id="ubicatec-data"', false)
        ->assertSee('data-testid="campus-map-static"', false)
        ->assertSee('Mapa del campus');
});

it('un usuario con sesión pero sin puesto también ve el mapa (D6: el Inicio es para todos los que tienen sesión)', function () {
    config(['nexus.campus_map_3d' => true]);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('data-testid="campus-map"', false);
});

it('MP-01: un periodo sin clases abre el mapa sin error', function () {
    config(['nexus.campus_map_3d' => true]);
    Term::factory()->create(['code' => '20263', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
    $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    actingAsRole($user, Role::Docente)
        ->get('/')
        ->assertOk()
        ->assertSee('id="ubicatec-data"', false);
});

it('TP-04: el mapa sigue el periodo de consulta de la credencial; sin elegir, el en curso (TP-03)', function () {
    config(['nexus.campus_map_3d' => true]);
    $this->travelTo('2026-11-24 10:00:00');
    Building::factory()->for($this->campus)->create(['code' => 'SC', 'name' => 'Edificio Sistemas', 'map_key' => 'Edificio Sistemas']);
    $previous = Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026', 'starts_on' => '2026-01-26', 'ends_on' => '2026-06-12']);
    Term::factory()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    actingAsRole($user, Role::Docente)
        ->get('/')
        ->assertOk()
        ->assertSee('sin clases publicadas en Ago-Dic 2026');

    actingAsRole($user, Role::Docente)
        ->withSession([Actor::VIEW_TERM => $previous->id])
        ->get('/')
        ->assertOk()
        ->assertSee('sin clases publicadas en Ene-Jun 2026')
        ->assertDontSee('sin clases publicadas en Ago-Dic 2026');
});

it('MP-02: el mapa de un periodo pasado o futuro lleva su tiempo y su aviso; el en curso, no', function () {
    config(['nexus.campus_map_3d' => true]);
    $this->travelTo('2026-11-24 10:00:00');
    $past = Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026', 'starts_on' => '2026-01-26', 'ends_on' => '2026-06-12']);
    $future = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);
    Term::factory()->current()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->campus->id])->create();

    actingAsRole($user, Role::Docente)->get('/')
        ->assertOk()->assertSee('"time":"current"', false)->assertDontSee('data-testid="map-time-note"', false);

    actingAsRole($user, Role::Docente)->withSession([Actor::VIEW_TERM => $past->id])->get('/')
        ->assertOk()->assertSee('"time":"past"', false)->assertSee('Periodo terminado')->assertSee('data-testid="map-time-note"', false);

    actingAsRole($user, Role::Docente)->withSession([Actor::VIEW_TERM => $future->id])->get('/')
        ->assertOk()->assertSee('"time":"future"', false)->assertSee('Periodo por empezar');
});
