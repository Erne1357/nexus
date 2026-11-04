<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use Livewire\Livewire;

/*
| «Personas y puestos» (`/people`, core::pages.people, maqueta C): la lista de todos los usuarios con un chip por puesto
| vigente y su estado. Solo el admin con ese perfil activo la ve (RoleAssignmentPolicy); cada cambio vuelve a
| autorizar, aunque llegue con una página que abrió el admin.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isc = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'username' => 'ada']);
    $this->rosa = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create(['name' => 'Rosa Ruiz', 'username' => '19110001']);
    $this->beto = User::factory()->create(['name' => 'Beto Sin Puesto', 'username' => '19110002']);
    $this->cris = User::factory()->create(['name' => 'Cris Inactivo', 'username' => '19110003', 'is_active' => false]);
});

function seesPeopleMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(Actor::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Personas y puestos');
}

it('el admin ve la lista con chips y estados, busca y filtra', function () {
    $this->actingAs($this->admin)
        ->get(route('core.people'))
        ->assertOk()
        ->assertSee('<title>Personas y puestos · Nexus</title>', false)
        ->assertSeeInOrder(['Usuario', 'Persona', 'Puestos vigentes', 'Estado'])
        ->assertSee('Coordinador de carrera · ISC · C2')
        ->assertSee('Con puesto')
        ->assertSee('Sin puesto vigente')
        ->assertSee('Usuario inactivo')
        ->assertSee('Ver puestos');

    $page = Livewire::actingAs($this->admin)->test('core::pages.people');

    $page->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Ada Admin', 'Beto Sin Puesto', 'Cris Inactivo', 'Rosa Ruiz'])
        ->set('search', '19110002')->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Beto Sin Puesto'])
        ->set('search', 'rosa')->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Rosa Ruiz'])
        ->set('search', '')
        ->set('role', 'coordinador')->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Rosa Ruiz'])
        ->set('role', 'admin')->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Ada Admin'])
        ->set('role', 'nada')->assertViewHas('people', fn ($people): bool => $people->count() === 4);
});

it('el menú muestra «Personas y puestos» solo al admin', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $item = collect(app(Navigation::class)->items())->firstWhere('label', 'Personas y puestos');

    expect(seesPeopleMenu($this->admin))->toBeTrue()
        ->and(seesPeopleMenu($coordinador))->toBeFalse()
        ->and(seesPeopleMenu($subdireccion))->toBeFalse()
        ->and($item)->route->toBe('core.people')->icon->toBe('o-users')->childRoutes->toBe(['core.people.show'])
        ->and(route('core.people', absolute: false))->toBe('/people');
});

it('un coordinador recibe 403 en la ruta', function () {
    $this->actingAs($this->rosa)->get(route('core.people'))->assertForbidden();
});

it('la misma persona con Coordinación activa recibe 403 aunque sea admin', function () {
    $both = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();

    actingAsRole($both, Role::Admin)->get(route('core.people'))->assertOk();
    actingAsRole($both, Role::Coordinador)->get(route('core.people'))->assertForbidden();
});

it('updated() rechaza a quien ya no es admin, sobre un componente que abrió el admin', function () {
    $page = Livewire::actingAs($this->admin)->test('core::pages.people');

    $this->actingAs($this->rosa);

    $page->set('search', 'rosa')->assertForbidden();
});
