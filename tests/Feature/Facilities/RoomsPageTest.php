<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Queries\RoomQuery;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CRUD de Salones (`/facilities/rooms`, facilities::pages.rooms) con los bloqueos del salón en un panel
| lateral. Los salones son de un campus: la pantalla trabaja con el campus activo de la credencial y, sin
| uno, pide elegirlo. Solo el admin y la Subdirección de ese campus (RoomPolicy). Cada acción del
| componente vuelve a autorizar.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->edA = Building::factory()->for($this->c2)->create(['code' => 'A', 'name' => 'Edificio A']);
    $this->edB = Building::factory()->for($this->c2)->create(['code' => 'B', 'name' => 'Laboratorios']);
    $this->edZ = Building::factory()->for($this->c1)->create(['code' => 'Z', 'name' => 'Edificio de C1']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica']);

    $this->a101 = Room::factory()->for($this->edA)->create(['code' => 'A-101', 'capacity' => 30]);
    $this->a102 = Room::factory()->for($this->edA)->ofType(RoomType::Auditorio)->capacity(120)->create(['code' => 'A-102', 'name' => 'Auditorio Mayor']);
    $this->b201 = Room::factory()->for($this->edB)->ofType(RoomType::Laboratorio)->ownedBy($this->sis)->create(['code' => 'B-201', 'features' => ['Proyector']]);
    $this->old = Room::factory()->for($this->edB)->inactive()->ownedBy($this->ele)->create(['code' => 'OLD-1']);
    $this->other = Room::factory()->for($this->edZ)->create(['code' => 'Z-900', 'name' => 'Salón de C1']);

    $this->admin = User::factory()->admin()->create();
    $this->sub = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
    session([ScopedContext::ACTIVE_CAMPUS => $this->c2->id]);
});

/**
 * @param  list<string>  $codes
 * @return Closure(mixed): bool
 */
function listsRooms(array $codes): Closure
{
    return fn ($rooms): bool => $rooms->pluck('code')->all() === $codes;
}

function seesRoomsMenu(User $user): bool
{
    test()->actingAs($user);

    return collect(app(Navigation::class)->visibleTo(app(ScopedContext::class)))
        ->contains(fn (NavigationItem $item): bool => $item->label === 'Salones');
}

describe('admin', function () {
    it('ve solo los salones del campus activo, con clave, edificio, tipo, capacidad, dueño y estado', function () {
        $response = $this->actingAs($this->admin)->get(route('facilities.rooms'));

        $response->assertOk()
            ->assertSeeInOrder(['Clave', 'Edificio', 'Tipo', 'Capacidad', 'Dueño', 'Estado'])
            ->assertSeeInOrder(['A-101', 'A-102', 'Auditorio Mayor', 'B-201', 'OLD-1', 'Inactivo'])
            ->assertDontSee('Z-900');

        $rows = collect(domOf($response)->querySelectorAll('table tbody tr'))->mapWithKeys(fn ($row) => [textOf($row->querySelector('td')) => textOf($row)]);

        expect($rows['A-102'])->toContain('A')->toContain('Auditorio')->toContain('120')->toContain('Sin dueño')
            ->and($rows['B-201'])->toContain('Laboratorio')->toContain('SIS');
    });

    it('en el celular apila cada salón en data-testid="room-cards"', function () {
        $cards = domOf($this->actingAs($this->admin)->get(route('facilities.rooms')))->querySelectorAll('[data-testid="room-cards"] > li');

        expect(count($cards))->toBe(4)->and(textOf($cards[1]))->toContain('A-102')->toContain('Auditorio Mayor')->toContain('120 lugares');
    });

    it('sin campus activo pide elegirlo y no lista nada', function () {
        session()->forget(ScopedContext::ACTIVE_CAMPUS);

        $this->actingAs($this->admin)->get(route('facilities.rooms'))
            ->assertOk()->assertSee('Elige un campus en tu credencial')->assertDontSee('A-101')->assertDontSee('Nuevo salón');
    });

    it('busca por clave o nombre y filtra por edificio, tipo, dueño y estado', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->assertViewHas('rooms', listsRooms(['A-101', 'A-102', 'B-201', 'OLD-1']))
            ->set('search', 'mayor')->assertViewHas('rooms', listsRooms(['A-102']))
            ->set('search', '')->set('buildingFilter', $this->edB->id)->assertViewHas('rooms', listsRooms(['B-201', 'OLD-1']))
            ->set('buildingFilter', '')->set('typeFilter', 'laboratorio')->assertViewHas('rooms', listsRooms(['B-201']))
            ->set('typeFilter', '')->set('ownerFilter', $this->ele->id)->assertViewHas('rooms', listsRooms(['OLD-1']))
            ->set('ownerFilter', 'sin_dueno')->assertViewHas('rooms', listsRooms(['A-101', 'A-102']))
            ->set('ownerFilter', '')->set('status', 'inactive')->assertViewHas('rooms', listsRooms(['OLD-1']))
            ->set('status', 'active')->assertViewHas('rooms', listsRooms(['A-101', 'A-102', 'B-201']));
    });

    it('olvida un filtro de edificio, tipo o dueño que no está entre las opciones del campus', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->set('buildingFilter', $this->edZ->id)->assertSet('buildingFilter', '')->assertViewHas('rooms', listsRooms(['A-101', 'A-102', 'B-201', 'OLD-1']))
            ->set('typeFilter', 'bodega')->assertSet('typeFilter', '')
            ->set('ownerFilter', 'no-es-uuid')->assertSet('ownerFilter', '');
    });

    it('la consulta no encuentra a nadie con un filtro que no es uuid o tipo, en lugar de tronar en la base', function () {
        $query = app(RoomQuery::class);
        $context = app(ScopedContext::class)->forUser($this->admin);

        foreach ([['no-es-uuid', null, null], [null, 'bodega', null], [null, null, 'no-es-uuid']] as [$building, $type, $owner]) {
            expect($query->forTable($context, $this->c2, '', $building, $type, $owner)->total())->toBe(0);
        }
    });

    it('da de alta un salón en el campus activo con características, sin select de campus', function () {
        $component = Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('create')->assertSet('showModal', true)->assertDontSee('Campus')
            ->set('building_id', $this->edA->id)->set('code', ' a-103 ')->set('name', 'Aula de posgrado')->set('type', 'aula')
            ->set('capacity', '25')->set('department_id', $this->sis->id)->set('features', 'Proyector, Aire acondicionado, proyector')
            ->call('save')->assertHasNoErrors()->assertSet('showModal', false);

        $room = Room::where('code', 'A-103')->sole();

        expect($room)->campus_id->toBe($this->c2->id)->building_id->toBe($this->edA->id)->capacity->toBe(25)
            ->department_id->toBe($this->sis->id)->features->toBe(['Proyector', 'Aire acondicionado'])
            ->and(lastToastOf($component)['title'])->toBe('Salón creado');
    });

    it('muestra errores en español: clave repetida, capacidad en cero y campos obligatorios', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('create')->set('building_id', $this->edA->id)->set('code', 'a-101')->set('type', 'aula')->set('capacity', '10')->call('save')
            ->assertHasErrors('code')->assertSee('Ya existe un salón con la clave A-101')
            ->set('code', 'A-104')->set('capacity', '0')->call('save')->assertHasErrors('capacity')
            ->set('code', '')->set('building_id', '')->set('type', '')->set('capacity', '')->call('save')
            ->assertHasErrors(['code', 'building_id', 'type', 'capacity'])->assertSee('El campo clave es obligatorio');
    });

    it('rechaza el edificio de otro campus aunque llegue del navegador', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('create')->set('building_id', $this->edZ->id)->set('code', 'X-1')->set('type', 'aula')->set('capacity', '10')->call('save')
            ->assertHasErrors('building_id')->assertSee('El edificio no es de este campus');
    });

    it('edita un salón, carga sus datos (características en una línea) y quita al dueño', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('edit', $this->b201->id)->assertSet('showModal', true)->assertSet('code', 'B-201')->assertSet('type', 'laboratorio')
            ->assertSet('capacity', '30')->assertSet('department_id', $this->sis->id)->assertSet('features', 'Proyector')
            ->set('capacity', '35')->set('department_id', '')->set('features', 'Proyector, Pizarrón')->call('save')->assertHasNoErrors();

        expect($this->b201->fresh())->capacity->toBe(35)->department_id->toBeNull()->features->toBe(['Proyector', 'Pizarrón'])
            ->and(Activity::where('subject_id', $this->b201->id)->where('event', 'updated')->exists())->toBeTrue();
    });

    it('desactiva y activa desde la tabla', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('toggleActive', $this->a101->id)->call('toggleActive', $this->old->id);

        expect($this->a101->fresh()->is_active)->toBeFalse()->and($this->old->fresh()->is_active)->toBeTrue();
    });

    it('un id que no es uuid es un 404', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')->call('edit', 'no-es-uuid')->assertNotFound();
    });

    it('no pasa de un puñado de consultas con 40 salones más: las mismas que con pocos', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get(route('facilities.rooms'))->assertOk();

            return count(DB::getQueryLog());
        };

        $queries();
        $few = $queries();
        Room::factory()->for($this->edA)->count(40)->create();

        expect($queries())->toBe($few);
    });
});

describe('bloqueos del salón en el panel', function () {
    beforeEach(function () {
        $this->monday = RoomBlock::factory()->for($this->a101)->create(['day' => 1, 'starts_at' => '08:00', 'ends_at' => '10:00', 'reason' => 'Mantenimiento']);
    });

    it('«Bloqueos» abre el panel con los bloqueos del salón', function () {
        $component = Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('openBlocks', $this->a101->id)->assertSet('showDrawer', true)->assertSet('blocksRoomId', $this->a101->id)
            ->assertSee('Mantenimiento')->assertSee('lunes');

        expect(textOf(domOf($component)->querySelector('[data-testid="blocks-drawer"]')))->toContain('Bloqueos de A-101')->toContain('08:00 a 10:00');
    });

    it('agrega un bloqueo dentro del perfil del campus y lo lista', function () {
        $term = Term::factory()->create();

        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('openBlocks', $this->a101->id)
            ->set('day', '3')->set('starts_at', '09:00')->set('ends_at', '11:00')->set('term_id', $term->id)->set('reason', 'Reservado para examen')
            ->call('addBlock')->assertHasNoErrors()->assertSee('Reservado para examen')->assertSet('reason', '');

        expect($this->a101->blocks()->where('day', 3)->sole())->term_id->toBe($term->id);
    });

    it('un bloqueo para todos los periodos queda sin periodo', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('openBlocks', $this->a101->id)
            ->set('day', '2')->set('starts_at', '07:00')->set('ends_at', '08:00')->set('term_id', '')->set('reason', 'Limpieza')->call('addBlock')->assertHasNoErrors();

        expect($this->a101->blocks()->where('day', 2)->sole()->term_id)->toBeNull();
    });

    it('muestra en español que el día o la hora no caben en el perfil del campus', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('openBlocks', $this->a101->id)
            ->set('day', '6')->set('starts_at', '09:00')->set('ends_at', '10:00')->set('reason', 'x')->call('addBlock')
            ->assertHasErrors('day')->assertSee('El campus no tiene clases ese día')
            ->set('day', '1')->set('starts_at', '06:00')->call('addBlock')->assertHasErrors('starts_at')->assertSee('dentro del horario del campus')
            ->set('starts_at', '11:00')->call('addBlock')->assertHasErrors('ends_at')->assertSee('La hora de fin debe ser posterior')
            ->set('starts_at', '09:00')->set('reason', '')->call('addBlock')->assertHasErrors('reason');
    });

    it('quita un bloqueo con borrado lógico', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('openBlocks', $this->a101->id)->call('removeBlock', $this->monday->id)->assertDontSee('Mantenimiento');

        expect(RoomBlock::count())->toBe(0)->and(RoomBlock::withTrashed()->count())->toBe(1);
    });

    it('los días del select son los del perfil del campus', function () {
        $this->c2->update(['settings' => [...$this->c2->settings, 'days' => [1, 6]]]);

        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('openBlocks', $this->a101->id)
            ->assertViewHas('dayOptions', fn (array $days): bool => array_column($days, 'id') === [1, 6]);
    });

    it('la subdirección de C2 administra los bloqueos de un salón de C2', function () {
        Livewire::actingAs($this->sub)->test('facilities::pages.rooms')
            ->call('openBlocks', $this->a101->id)
            ->set('day', '4')->set('starts_at', '09:00')->set('ends_at', '10:00')->set('reason', 'Evento')->call('addBlock')->assertHasNoErrors()
            ->call('removeBlock', $this->monday->id)->assertHasNoErrors();

        expect($this->a101->blocks()->count())->toBe(1);
    });

    it('la subdirección de C1 no abre, agrega ni quita bloqueos de un salón de C2', function () {
        $c1 = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();
        session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id]);

        Livewire::actingAs($c1)->test('facilities::pages.rooms')->call('openBlocks', $this->a101->id)->assertForbidden();
        Livewire::actingAs($c1)->test('facilities::pages.rooms')->call('removeBlock', $this->monday->id)->assertForbidden();
        expect(RoomBlock::count())->toBe(1);
    });

    it('agregar sin haber abierto un salón no hace nada y da 403', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')->call('addBlock')->assertForbidden();
    });
});

describe('robustez y perfil del campus', function () {
    it('si el salón del panel se borra, cierra el panel en lugar de romper la página', function () {
        $component = Livewire::actingAs($this->admin)->test('facilities::pages.rooms')->call('openBlocks', $this->a101->id)->assertSet('showDrawer', true);
        $this->a101->delete();

        $component->call('$refresh')->assertOk()->assertSet('showDrawer', false)->assertSet('blocksRoomId', null);
    });

    it('si el usuario pierde el alcance del salón del panel, lo cierra sin 403', function () {
        $component = Livewire::actingAs($this->sub)->test('facilities::pages.rooms')->call('openBlocks', $this->a101->id);
        $this->sub->roleScopes()->update(['valid_to' => today()->subDay()]);
        app(ScopedContext::class)->refresh();

        $component->call('$refresh')->assertOk()->assertSet('showDrawer', false);
    });

    it('quitar un bloqueo ya quitado no da error ni avisa de éxito', function () {
        $block = RoomBlock::factory()->for($this->a101)->create();
        $component = Livewire::actingAs($this->admin)->test('facilities::pages.rooms')->call('openBlocks', $this->a101->id)->call('removeBlock', $block->id);
        $activities = Activity::count();

        $component->call('removeBlock', $block->id)->assertOk();

        expect(Activity::count())->toBe($activities)->and(lastToastOf($component)['title'] ?? null)->not->toBe('Bloqueo quitado');
    });

    it('rechaza un dueño desactivado y no lo ofrece en el select, salvo el que el salón ya tiene', function () {
        $dead = Department::factory()->inactive()->create(['code' => 'DEAD']);
        $this->old->update(['department_id' => $dead->id]);

        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->assertViewHas('ownerOptions', fn (array $o): bool => ! in_array($dead->id, array_column($o, 'id'), true))
            ->call('edit', $this->old->id)
            ->assertViewHas('ownerOptions', fn (array $o): bool => in_array($dead->id, array_column($o, 'id'), true))
            ->call('create')->set('building_id', $this->edA->id)->set('code', 'N-1')->set('type', 'aula')->set('capacity', '10')->set('department_id', $dead->id)
            ->call('save')->assertHasErrors('department_id')->assertSee('El departamento dueño está desactivado');
    });

    it('rechaza capacidad menor que uno y características de más de 40 caracteres', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->call('create')->set('building_id', $this->edA->id)->set('code', 'N-2')->set('type', 'aula')->set('capacity', '-3')->call('save')->assertHasErrors('capacity')
            ->set('capacity', '10')->set('features', 'Proyector, '.str_repeat('x', 41))->call('save')->assertHasErrors('features')->assertSee('hasta 40 caracteres')
            ->set('features', 'Proyector, '.str_repeat('x', 40))->call('save')->assertHasNoErrors();
    });

    it('un campus que no usa salones muestra su estado vacío y no deja crear (por perfil, no por código)', function () {
        $this->c2->update(['settings' => [...$this->c2->settings, 'uses_rooms' => false]]);

        $this->actingAs($this->admin)->get(route('facilities.rooms'))
            ->assertOk()->assertSee('no usa salones')->assertDontSee('A-101');
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')->assertViewHas('rooms', null)->call('create')->assertForbidden();
        $this->actingAs($this->admin)->get(route('facilities.buildings'))->assertOk()->assertSee('Edificio A');
    });
});

describe('subdirección', function () {
    it('de C2 ve y administra los salones de C2', function () {
        Livewire::actingAs($this->sub)->test('facilities::pages.rooms')
            ->assertViewHas('rooms', listsRooms(['A-101', 'A-102', 'B-201', 'OLD-1']))
            ->call('create')->set('building_id', $this->edB->id)->set('code', 'B-202')->set('type', 'taller')->set('capacity', '20')->call('save')->assertHasNoErrors()
            ->call('toggleActive', $this->b201->id);

        expect(Room::where('code', 'B-202')->exists())->toBeTrue()->and($this->b201->fresh()->is_active)->toBeFalse();
    });

    it('de C1 no puede editar ni desactivar un salón de C2 llamando directo', function () {
        $c1 = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->create();
        session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id]);

        Livewire::actingAs($c1)->test('facilities::pages.rooms')->call('edit', $this->a101->id)->assertForbidden();
        Livewire::actingAs($c1)->test('facilities::pages.rooms')->call('toggleActive', $this->a101->id)->assertForbidden();
        expect($this->a101->fresh()->is_active)->toBeTrue();
    });

    it('con un campus activo que no administra no ve nada ni puede crear', function () {
        $multi = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(1)])->create();
        session([ScopedContext::ACTIVE_CAMPUS => $this->c1->id]);

        Livewire::actingAs($multi)->test('facilities::pages.rooms')->assertViewHas('rooms', listsRooms([]))->call('create')->assertForbidden();
    });
});

describe('otros roles', function () {
    it('el coordinador, el jefe y el docente reciben 403 en la ruta y no ven la entrada del menú', function (Role $role, array $scope) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

        $this->actingAs($user)->get(route('facilities.rooms'))->assertForbidden();
        expect(seesRoomsMenu($user))->toBeFalse();
    })->with([
        'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
        'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
        'docente' => [Role::Docente, []],
    ]);

    it('cada método llamado directo da 403, también sobre una página que abrió el admin', function (string $method, bool $editFirst) {
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $block = RoomBlock::factory()->for($this->a101)->create();
        $page = Livewire::actingAs($this->admin)->test('facilities::pages.rooms')->call('openBlocks', $this->a101->id);
        $activities = Activity::count();

        if ($editFirst) {
            $page->call('edit', $this->a101->id)->set('capacity', '99');
        }

        $this->actingAs($coordinator);
        $page->call($method, ...match ($method) {
            'edit', 'toggleActive', 'openBlocks' => [$this->a101->id],
            'removeBlock' => [$block->id],
            default => [],
        })->assertForbidden();

        expect(Room::count())->toBe(5)->and(RoomBlock::count())->toBe(1)->and($this->a101->fresh())->is_active->toBeTrue()->capacity->toBe(30)
            ->and(Activity::count())->toBe($activities);
    })->with([
        'nuevo' => ['create', false],
        'editar' => ['edit', false],
        'guardar un alta' => ['save', false],
        'guardar una edición' => ['save', true],
        'activar o desactivar' => ['toggleActive', false],
        'abrir bloqueos' => ['openBlocks', false],
        'agregar un bloqueo' => ['addBlock', false],
        'quitar un bloqueo' => ['removeBlock', false],
    ]);

    it('el invitado va al login', function () {
        $this->get(route('facilities.rooms'))->assertRedirect(route('login'));
    });

    it('el admin y la subdirección ven la entrada «Salones» del menú', function () {
        expect(seesRoomsMenu($this->admin))->toBeTrue()->and(seesRoomsMenu($this->sub))->toBeTrue();
    });
});
