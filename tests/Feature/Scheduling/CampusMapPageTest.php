<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

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
