<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CRUD de Edificios (`/facilities/buildings`, facilities::pages.buildings). Los edificios son de un campus y la
| credencial ya no lo elige (#415): la pantalla lista los de todos los campus del usuario, con su campus en una
| columna y un filtro (`?campus=`) con «Todos»; el alta pide el campus en el formulario. Solo el admin y la
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

    return collect(app(Navigation::class)->visibleTo(app(Actor::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Edificios');
}

describe('admin', function () {
    it('ve los edificios de todos los campus, con clave, nombre, campus, salones sin borrar y estado', function () {
        $response = $this->actingAs($this->admin)->get(route('facilities.buildings'));

        $response->assertOk()
            ->assertSeeInOrder(['Clave', 'Nombre', 'Campus', 'Salones', 'Estado'])
            ->assertSeeInOrder(['A', 'Edificio A', 'C2', 'B', 'Laboratorios', 'OLD', 'Edificio Antiguo', 'Inactivo', 'Z', 'Edificio de C1', 'C1']);

        $rows = collect(domOf($response)->querySelectorAll('table tbody tr'))->mapWithKeys(fn ($row) => [textOf($row->querySelector('td')) => textOf($row)]);

        expect($rows['A'])->toContain('2')->and($rows['B'])->toContain('0');
    });

    it('en el celular apila cada edificio en data-testid="building-cards"', function () {
        $cards = domOf($this->actingAs($this->admin)->get(route('facilities.buildings')))->querySelectorAll('[data-testid="building-cards"] > li');

        expect(count($cards))->toBe(4)->and(textOf($cards[0]))->toContain('A Edificio A Activo')->toContain('2 salones');
    });

    it('sin elegir campus lista todos y ya no pide elegir uno en la credencial', function () {
        $this->actingAs($this->admin)->get(route('facilities.buildings'))
            ->assertOk()->assertDontSee('Elige un campus en tu credencial')->assertSee('Nuevo edificio');

        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD', 'Z']));
    });

    it('filtra por campus con el de la página (?campus=) y «Todos» los vuelve a mostrar', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->set('campus', 'C1')->assertViewHas('buildings', listsBuildings(['Z']))
            ->set('campus', 'C2')->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD']))
            ->set('campus', null)->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD', 'Z']))
            ->set('campus', 'C9')->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD', 'Z']));

        Livewire::withQueryParams(['campus' => 'C1'])->actingAs($this->admin)->test('facilities::pages.buildings')
            ->assertSet('campus', 'C1')->assertViewHas('buildings', listsBuildings(['Z']));
    });

    it('busca por clave o nombre y filtra por estado', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD', 'Z']))
            ->set('search', 'antiguo')->assertViewHas('buildings', listsBuildings(['OLD']))
            ->set('search', '')->set('status', 'active')->assertViewHas('buildings', listsBuildings(['A', 'B', 'Z']))
            ->set('status', 'inactive')->assertViewHas('buildings', listsBuildings(['OLD']));
    });

    it('da de alta un edificio en el campus que elige en el formulario y avisa con un toast escapado', function () {
        $component = Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->call('create')->assertSet('showModal', true)->assertSet('campus_id', '')->assertSee('Elige el campus')
            ->set('code', ' c ')->set('name', '<b>Edificio C</b>')->set('campus_id', $this->c1->id)->call('save')->assertHasNoErrors()->assertSet('showModal', false);

        $building = Building::where('code', 'C')->sole();

        expect($building->campus_id)->toBe($this->c1->id)->and($building->is_active)->toBeTrue()
            ->and(lastToastOf($component)['title'])->toBe('Edificio creado')
            ->and(lastToastOf($component)['description'])->toContain('&lt;b&gt;');
    });

    it('propone el campus del filtro en el alta, y exige elegir uno si no hay filtro', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->set('campus', 'C1')->call('create')->assertSet('campus_id', $this->c1->id)
            ->set('campus', null)->call('create')->assertSet('campus_id', '')
            ->set('code', 'Q')->set('name', 'Sin campus')->call('save')->assertHasErrors('campus_id');

        expect(Building::where('code', 'Q')->exists())->toBeFalse();
    });

    it('muestra el error en español de una clave repetida o campos vacíos', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.buildings')
            ->call('create')->set('campus_id', $this->c2->id)->set('code', 'a')->set('name', 'Otro')->call('save')
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
            ->call('create')->assertSet('campus_id', $this->c2->id)->set('code', 'D')->set('name', 'Edificio D')->call('save')->assertHasNoErrors()
            ->call('toggleActive', $this->b->id);

        expect(Building::where('code', 'D')->exists())->toBeTrue()->and($this->b->fresh()->is_active)->toBeFalse();
    });

    it('con Coordinación activa (la que trae C1 a su credencial) no entra; con Subdirección activa, C1 guardado en la sesión no cuenta', function () {
        vacate(Role::Subdireccion);
        $multi = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id, 'valid_from' => today()->toDateString()])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(1)])->create();

        actingAsRole($multi, Role::Coordinador);

        Livewire::test('facilities::pages.buildings')->assertForbidden();

        actingAsRole($multi, Role::Subdireccion);

        Livewire::test('facilities::pages.buildings')->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD']));
    });

    it('no ve los edificios de otro campus ni los puede dar de alta allí, aunque pida su campus en la URL o el formulario', function () {
        Livewire::actingAs($this->sub)->test('facilities::pages.buildings')
            ->set('campus', 'C1')->assertViewHas('buildings', listsBuildings(['A', 'B', 'OLD']))->assertDontSee('Edificio de C1')
            ->call('create')->set('campus_id', $this->c1->id)->set('code', 'Y')->set('name', 'Intruso')->call('save')->assertHasErrors('campus_id');

        expect(Building::where('code', 'Y')->exists())->toBeFalse();
    });

    it('de C1 no puede editar ni desactivar un edificio de C2 llamando directo', function () {
        vacate(Role::Subdireccion);
        $c1 = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id, 'valid_from' => today()->toDateString()])->create();

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
