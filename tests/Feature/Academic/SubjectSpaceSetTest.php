<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| SEL-17: el tipo de espacio de una materia lo cambian el jefe de su departamento y el admin, desde el catálogo de
| Materias (`academic.subject.set_space`, alcance: el departamento de la materia).
*/

beforeEach(function () {
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->ele = Department::factory()->create(['code' => 'ELE']);
    $this->redes = Subject::factory()->create(['code' => 'SCA-1001', 'department_id' => $this->sis->id, 'required_space' => null, 'needs_lab' => false]);
    $this->circuitos = Subject::factory()->create(['code' => 'AEF-1002', 'department_id' => $this->ele->id, 'required_space' => null]);

    $this->jefeSis = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create();
    $this->jefeEle = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create();
});

it('SEL-17: el jefe cambia el tipo de espacio de una materia de su departamento y queda en la bitácora', function () {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')
        ->call('editSpace', $this->redes->id)
        ->set('spaceChoice', RequiredSpace::Laboratorio->value)
        ->call('saveSpace')
        ->assertHasNoErrors();

    expect($this->redes->fresh())->required_space->toBe(RequiredSpace::Laboratorio)->needs_lab->toBeTrue()
        ->and(Activity::query()->where('subject_id', $this->redes->id)->where('causer_id', $this->jefeSis->id)->exists())->toBeTrue();
});

it('SEL-17: el jefe ve solo las materias de su departamento y su control, sin alta ni edición', function () {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')
        ->assertOk()
        ->assertSee('SCA-1001')
        ->assertDontSee('AEF-1002')
        ->assertSeeHtml('aria-label="Tipo de espacio de SCA-1001"')
        ->assertDontSeeHtml('wire:click="create"')
        ->assertDontSeeHtml('aria-label="Editar SCA-1001"')
        ->assertDontSee('Editar SCA-1001');
});

it('SEL-17: el jefe de otro departamento no ve las materias ni puede llamar la acción', function () {
    actingAsRole($this->jefeEle, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->assertDontSee('SCA-1001');
    Livewire::test('academic::pages.subjects')->call('editSpace', $this->redes->id)->assertForbidden();

    expect($this->redes->fresh()->required_space)->toBeNull();
});

it('SEL-17: el jefe no edita, da de alta ni desactiva los demás datos de la materia', function (string $method, array $arguments) {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->call($method, ...$arguments)->assertForbidden();
})->with([
    'editar' => fn () => ['edit', [$this->redes->id]],
    'crear' => ['create', []],
    'desactivar' => fn () => ['toggleActive', [$this->redes->id]],
]);

it('SEL-17: el jefe de proyecto docente y el docente no ven el catálogo ni cambian el tipo', function (Role $role) {
    $user = User::factory()->withRole($role, ['department_id' => $this->sis->id])->create();
    actingAsRole($user, $role);

    expect($user->can('viewAny', Subject::class))->toBeFalse()
        ->and($user->can('setSpace', $this->redes))->toBeFalse();
    $this->get(route('academic.subjects'))->assertForbidden();
})->with([Role::JefeProyectoDocente, Role::Docente]);

it('SEL-17: el admin cambia el tipo de cualquier materia y filtra por tipo de espacio, incluido «Sin marcar»', function () {
    $admin = User::factory()->admin()->create();
    actingAsRole($admin, Role::Admin);

    Livewire::test('academic::pages.subjects')
        ->call('editSpace', $this->circuitos->id)
        ->set('spaceChoice', RequiredSpace::SalaComputo->value)
        ->call('saveSpace')
        ->set('spaceFilter', 'none')
        ->assertSee('SCA-1001')->assertDontSee('AEF-1002')
        ->set('spaceFilter', RequiredSpace::SalaComputo->value)
        ->assertSee('AEF-1002')->assertDontSee('SCA-1001');

    expect($this->circuitos->fresh()->required_space)->toBe(RequiredSpace::SalaComputo);
});

it('SEL-17: una materia sin marcar abre el drawer sin valor y guardar exige elegir uno', function () {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')
        ->call('editSpace', $this->redes->id)
        ->assertSet('spaceChoice', '')
        ->call('saveSpace')
        ->assertHasErrors(['spaceChoice']);

    expect($this->redes->fresh()->required_space)->toBeNull();
});

it('SEL-17: el jefe que pone el filtro de otro departamento sigue sin ver sus materias', function () {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')
        ->set('departmentFilter', $this->ele->id)
        ->assertDontSee('AEF-1002')
        ->assertSee('SCA-1001');
});

it('SEL-17: una página abierta por el admin no deja guardar al jefe de otro departamento', function () {
    $admin = User::factory()->admin()->create();
    $page = Livewire::actingAs($admin)->test('academic::pages.subjects')
        ->call('editSpace', $this->redes->id)
        ->set('spaceChoice', RequiredSpace::Laboratorio->value);

    actingAsRole($this->jefeEle, Role::JefeDepartamento);
    $page->call('saveSpace')->assertForbidden();

    expect($this->redes->fresh()->required_space)->toBeNull();
});

it('SEL-17: un tipo de espacio que no existe falla la validación y no escribe', function () {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')
        ->call('editSpace', $this->redes->id)
        ->set('spaceChoice', 'x')
        ->call('saveSpace')
        ->assertHasErrors(['spaceChoice']);

    expect($this->redes->fresh()->required_space)->toBeNull();
});

it('SEL-17: un filtro de tipo de espacio que no existe se olvida', function () {
    actingAsRole($this->jefeSis, Role::JefeDepartamento);

    Livewire::test('academic::pages.subjects')->set('spaceFilter', 'x')->assertSet('spaceFilter', '')->assertSee('SCA-1001');
});
