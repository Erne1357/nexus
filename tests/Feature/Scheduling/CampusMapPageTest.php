<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
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
