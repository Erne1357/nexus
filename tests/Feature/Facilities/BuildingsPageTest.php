<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CRUD de Edificios (`/facilities/buildings`, facilities::pages.buildings). Los edificios son de un campus:
| la pantalla trabaja con el campus activo de la credencial y, sin uno, pide elegirlo. Solo el admin y la
| Subdirección de ese campus (BuildingPolicy). Cada acción del componente vuelve a autorizar.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->a = Building::factory()->for($this->c2)->create(['code' => 'A', 'name' => 'Edificio A']);
    $this->b = Building::factory()->for($this->c2)->create(['code' => 'B', 'name' => 'Laboratorios']);
    $this->old = Building::factory()->for($this->c2)->inactive()->create(['code' => 'OLD', 'name' => 'Edificio Antiguo']);
    $this->other = Building::factory()->for($this->c1)->create(['code' => 'Z', 'name' => 'Edificio de C1']);
    Room::factory()->for($this->a)->count(2)->create();
    Room::factory()->for($this->a)->create()->delete();

    $this->admin = User::factory()->admin()->create();
    $this->sub = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
    session([ScopedContext::ACTIVE_CAMPUS => $this->c2->id]);
});

/**
 * @param  list<string>  $codes
 * @return Closure(mixed): bool
 */
function listsBuildings(array $codes): Closure
{
    return fn ($buildings): bool => $buildings->pluck('code')->all() === $codes;
}

function seesBuildingsMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Edificios');
}

describe('admin', function () {
    it('ve solo los edificios del campus activo, con clave, nombre, salones sin borrar y estado', function () {
        $response = $this->actingAs($this->admin)->get(route('facilities.buildings'));

        $response->assertOk()
            ->assertSeeInOrder(['Clave', 'Nombre', 'Salones', 'Estado'])
            ->assertSeeInOrder(['A', 'Edificio A', 'B', 'Laboratorios', 'OLD', 'Edificio Antiguo', 'Inactivo'])
            ->assertDontSee('Edificio de C1');

        $rows = collect(domOf($response)->querySelectorAll('table tbody tr'))->mapWithKeys(fn ($row) => [textOf($row->querySelector('td')) => textOf($row)]);

        expect($rows['A'])->toContain('2')->and($rows['B'])->toContain('0');
    });

    it('en el celular apila cada edificio en data-testid="building-cards"', function () {
        $cards = domOf($this->actingAs($this->admin)->get(route('facilities.buildings')))->querySelectorAll('[data-testid="building-cards"] > li');

        expect(count($cards))->toBe(3)->and(textOf($cards[0]))->toContain('A Edificio A Activo')->toContain('2 salones');
    });

    it('sin campus activo pide elegirlo y no lista nada', function () {
        session()->forget(ScopedContext::ACTIVE_CAMPUS);

        $this->actingAs($this->admin)->get(route('facilities.buildings'))
            ->assertOk()->assertSee('Elige un campus en tu credencial')->assertDontSee('Edificio A')->assertDontSee('Nuevo edificio');
    });

    it('cambia de campus con la credencial', function () {
        session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id]);

        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')->assertViewHas('buildings', listsBuildings(['Z']));
    });

    it('busca por clave o nombre y filtra por estado', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD']))
            ->set('search', 'antiguo')->assertViewHas('buildings', listsBuildings(['OLD']))
            ->set('search', '')->set('status', 'active')->assertViewHas('buildings', listsBuildings(['A', 'B']))
            ->set('status', 'inactive')->assertViewHas('buildings', listsBuildings(['OLD']));
    });

    it('da de alta un edificio en el campus activo, sin select de campus, y avisa con un toast escapado', function () {
        $component = Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->call('create')->assertSet('showModal', true)
            ->set('code', ' c ')->set('name', '<b>Edificio C</b>')->call('save')->assertHasNoErrors()->assertSet('showModal', false);

        $building = Building::where('code', 'C')->sole();

        expect($building->campus_id)->toBe($this->c2->id)->and($building->is_active)->toBeTrue()
            ->and(lastToastOf($component)['title'])->toBe('Edificio creado')
            ->and(lastToastOf($component)['description'])->toContain('&lt;b&gt;');
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')->call('create')->assertDontSee('Campus');
    });

    it('muestra el error en español de una clave repetida o campos vacíos', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->call('create')->set('code', 'a')->set('name', 'Otro')->call('save')
            ->assertHasErrors('code')->assertSee('Ya existe un edificio con la clave A')
            ->set('code', '')->set('name', '')->call('save')->assertHasErrors(['code', 'name'])->assertSee('El campo clave es obligatorio');
    });

    it('edita un edificio, carga sus datos y registra en la bitácora', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->call('edit', $this->b->id)->assertSet('showModal', true)->assertSet('code', 'B')->assertSet('name', 'Laboratorios')
            ->set('name', 'Laboratorios y talleres')->call('save')->assertHasNoErrors();

        expect($this->b->fresh()->name)->toBe('Laboratorios y talleres')
            ->and(Activity::where('subject_id', $this->b->id)->where('event', 'updated')->exists())->toBeTrue();
    });

    it('desactiva y activa desde la tabla', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->call('toggleActive', $this->a->id)->call('toggleActive', $this->old->id);

        expect($this->a->fresh()->is_active)->toBeFalse()->and($this->old->fresh()->is_active)->toBeTrue();
    });

    it('un id que no es uuid es un 404, no un error de la base', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')->call('edit', 'no-es-uuid')->assertNotFound();
    });
});

describe('subdirección', function () {
    it('de C2 ve y administra los edificios de C2', function () {
        Livewire::actingAs($this->sub)->test('facilities::pages.buildings')
            ->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD']))
            ->call('create')->set('code', 'D')->set('name', 'Edificio D')->call('save')->assertHasNoErrors()
            ->call('toggleActive', $this->b->id);

        expect(Building::where('code', 'D')->exists())->toBeTrue()->and($this->b->fresh()->is_active)->toBeFalse();
    });

    it('con C1 como campus activo (donde no es subdirección) no ve nada ni puede crear', function () {
        $multi = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(1)])->create();
        session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id]);

        Livewire::actingAs($multi)->test('facilities::pages.buildings')
            ->assertViewHas('buildings', listsBuildings([]))
            ->call('create')->assertForbidden();
    });

    it('de C1 no puede editar ni desactivar un edificio de C2 llamando directo', function () {
        $c1 = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();
        session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id]);

        Livewire::actingAs($c1)->test('facilities::pages.buildings')->call('edit', $this->a->id)->assertForbidden();
        Livewire::actingAs($c1)->test('facilities::pages.buildings')->call('toggleActive', $this->a->id)->assertForbidden();
        expect($this->a->fresh()->is_active)->toBeTrue();
    });
});

describe('otros roles', function () {
    it('el coordinador, el jefe y el docente reciben 403 en la ruta y no ven la entrada del menú', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

        $this->actingAs($user)->get(route('facilities.buildings'))->assertForbidden();
        expect(seesBuildingsMenu($user))->toBeFalse();
    })->with([
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ]);

    it('cada método llamado directo da 403, también sobre una página que abrió el admin', function (string $method, bool $editFirst) {
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $page = Livewire::actingAs($this->admin)->test('facilities::pages.buildings');
        $activities = Activity::count();

        if ($editFirst) {
            $page->call('edit', $this->a->id)->set('name', 'Cambiado');
        }

        $this->actingAs($coordinator);
        $page->call($method, ...(in_array($method, ['edit', 'toggleActive'], true) ? [$this->a->id] : []))->assertForbidden();

        expect(Building::count())->toBe(4)->and($this->a->fresh())->is_active->toBeTrue()->name->toBe('Edificio A')
            ->and(Activity::count())->toBe($activities);
    })->with([
        'nuevo' => ['create', false],
        'editar' => ['edit', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
        'activar o desactivar' => ['toggleActive', false],
    ]);

    it('el invitado va al login', function () {
        $this->get(route('facilities.buildings'))->assertRedirect(route('login'));
    });

    it('el admin y la subdirección ven la entrada «Edificios» del menú', function () {
        expect(seesBuildingsMenu($this->admin))->toBeTrue()->and(seesBuildingsMenu($this->sub))->toBeTrue();
    });
});
