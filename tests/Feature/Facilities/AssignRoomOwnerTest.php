<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Actions\AssignRoomOwner;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Queries\RoomQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| Asignar dueño a salones en bloque (D1 de la ronda de validación, 14 de noviembre de 2026): una Action en una sola
| transacción, con una entrada de bitácora por salón cambiado, y solo para quien administra salones del campus.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->edA = Building::factory()->for($this->c2)->create(['code' => 'A']);
    $this->edB = Building::factory()->for($this->c2)->create(['code' => 'B']);
    $this->edZ = Building::factory()->for($this->c1)->create(['code' => 'Z']);
    $this->sis = Department::factory()->create(['code' => 'SIS']);
    $this->ele = Department::factory()->create(['code' => 'ELE']);
    $this->r1 = Room::factory()->for($this->edA)->create(['code' => 'A-101']);
    $this->r2 = Room::factory()->for($this->edA)->create(['code' => 'A-102']);
    $this->r3 = Room::factory()->for($this->edB)->ownedBy($this->ele)->create(['code' => 'B-201']);
    $this->outside = Room::factory()->for($this->edZ)->create(['code' => 'Z-900']);
    $this->admin = User::factory()->admin()->create();
    $this->sub = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
});

describe('Action (S12)', function () {
    it('asigna el dueño a varios salones con una entrada de bitácora por cada uno', function () {
        $before = Activity::count();

        $changed = app(AssignRoomOwner::class)->handle($this->admin, [$this->r1->id, $this->r2->id, $this->r3->id], $this->sis->id);

        expect($changed)->toBe(3)
            ->and(Room::where('department_id', $this->sis->id)->count())->toBe(3)
            ->and(Activity::count() - $before)->toBe(3);
        $log = Activity::query()->where('subject_id', $this->r3->id)->latest('id')->first();
        expect($log->attribute_changes['old']['department_id'])->toBe($this->ele->id)
            ->and($log->attribute_changes['attributes']['department_id'])->toBe($this->sis->id);
    });

    it('deja los salones sin dueño con null y no registra los que ya estaban así', function () {
        $before = Activity::count();

        $changed = app(AssignRoomOwner::class)->handle($this->admin, [$this->r1->id, $this->r3->id], null);

        expect($changed)->toBe(1)->and($this->r3->fresh()->department_id)->toBeNull()->and(Activity::count() - $before)->toBe(1);
    });

    it('es todo o nada: un salón fuera del alcance impide cambiar los demás', function () {
        $before = Activity::count();

        expect(fn () => app(AssignRoomOwner::class)->handle($this->sub, [$this->r1->id, $this->outside->id], $this->sis->id))
            ->toThrow(AuthorizationException::class);

        expect($this->r1->fresh()->department_id)->toBeNull()->and(Activity::count())->toBe($before);
    });

    it('rechaza un departamento que no existe y una lista vacía', function () {
        expect(fn () => app(AssignRoomOwner::class)->handle($this->admin, [$this->r1->id], (string) fake()->uuid()))->toThrow(ValidationException::class)
            ->and(fn () => app(AssignRoomOwner::class)->handle($this->admin, [], $this->sis->id))->toThrow(ValidationException::class);
    });
});

describe('pantalla (S12)', function () {
    it('la subdirección elige salones, los asigna a un departamento y la selección se vacía', function () {
        Livewire::actingAs($this->sub)->test('facilities::pages.rooms')
            ->set('selected', [$this->r1->id, $this->r2->id])
            ->call('openOwnerModal')
            ->set('bulkOwner', $this->sis->id)
            ->call('assignOwner')
            ->assertHasNoErrors()
            ->assertSet('selected', [])
            ->assertSet('showOwnerModal', false);

        expect(Room::where('department_id', $this->sis->id)->pluck('code')->sort()->values()->all())->toBe(['A-101', 'A-102']);
    });

    it('«Elegir los N de la lista» elige los de todas las páginas con el filtro de edificio', function () {
        Room::factory()->for($this->edB)->count(20)->create();

        $page = Livewire::actingAs($this->sub)->test('facilities::pages.rooms')
            ->set('buildingFilter', $this->edB->id)
            ->call('chooseAll');

        expect($page->get('selected'))->toHaveCount(21);

        $page->set('ownerFilter', RoomQuery::WITHOUT_OWNER)->assertSet('selected', []);
    });

    it('deja sin dueño con el departamento vacío', function () {
        Livewire::actingAs($this->admin)->test('facilities::pages.rooms')
            ->set('selected', [$this->r3->id])
            ->call('openOwnerModal')
            ->call('assignOwner');

        expect($this->r3->fresh()->department_id)->toBeNull();
    });

    it('un coordinador no puede asignar dueño ni abrir el modal, y la subdirección no toca salones de otro campus', function () {
        $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

        Livewire::actingAs($coordinator)->test('facilities::pages.rooms')->assertForbidden();

        Livewire::actingAs($this->sub)->test('facilities::pages.rooms')
            ->set('selected', [$this->r1->id, $this->outside->id])
            ->set('bulkOwner', $this->sis->id)
            ->call('assignOwner')
            ->assertForbidden();

        expect(Room::whereNotNull('department_id')->count())->toBe(1);
    });
});
