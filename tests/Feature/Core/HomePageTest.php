<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;

/*
| Página de inicio (`/`, core::pages.home): saludo y un bloque por cada rol que el usuario ejerce
| según ScopedContext::has(). El admin, que tiene todos los roles, ve solo el suyo.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

it('saluda al usuario y muestra el bloque de cada rol que ejerce', function () {
    $user = User::factory()
        ->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => 10])
        ->withRole(Role::Docente, ['campus_id' => $this->campus->id])
        ->create(['name' => 'Ana López']);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Hola, Ana López')
        ->assertSee('Jefe de departamento académico')
        ->assertSee('Docente')
        ->assertDontSee('Coordinador de carrera')
        ->assertDontSee('Administración');
});

it('al coordinador le muestra solo el bloque de coordinación de carrera', function () {
    $this->actingAs(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => 20])->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Coordinador de carrera')
        ->assertSee('propones qué materias y cuántos grupos abrir')
        ->assertDontSee('Jefe de departamento académico')
        ->assertDontSee('Administración');
});

it('al admin le muestra el bloque de administración y no uno por cada rol', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Administración')
        ->assertDontSee('Coordinador de carrera')
        ->assertDontSee('Jefe de departamento académico');
});

it('a quien no tiene un rol vigente le dice que pida uno', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => 20])->create();
    RoleScope::where('user_id', $user->id)->update(['valid_to' => today()->subDay()]);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Todavía no tienes un rol vigente en Nexus')
        ->assertDontSee('Coordinador de carrera');
});

it('se muestra dentro del layout de la aplicación', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('<title>Inicio · Nexus</title>', false)
        ->assertSee('Navegación principal')
        ->assertSee('Cerrar sesión');
});

it('un invitado va al login', function () {
    $this->get('/')->assertRedirect('/login');
});
