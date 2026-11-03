<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Livewire;

/*
| AZ-S5: una pestaña pintada con un perfil no puede mandar acciones después de que la sesión cambió de perfil
| en otra pestaña (Core\Livewire\ActiveRoleHook).
*/

beforeEach(function () {
    $campus = Campus::factory()->create(['code' => 'C2']);

    $this->persona = User::factory()
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $campus->id, 'department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => careerKey(20)])
        ->create();
});

it('una acción de un componente pintado con otro perfil responde 419', function () {
    actingAsRole($this->persona, Role::JefeProyectoDocente);

    $component = Livewire::test('core::components.context-switcher');

    session()->put(ScopedContext::ACTIVE_ROLE, Role::Coordinador->value);

    $component->call('$refresh')->assertStatus(419)->assertHeader('X-Nexus-Role-Changed', '1');
});

it('con el mismo perfil la acción procede', function () {
    actingAsRole($this->persona, Role::JefeProyectoDocente);

    Livewire::test('core::components.context-switcher')
        ->call('$refresh')
        ->assertOk();
});

it('un componente sin usuario autenticado no se ve afectado', function () {
    Livewire::test('core::components.context-switcher')
        ->call('$refresh')
        ->assertOk();
});
