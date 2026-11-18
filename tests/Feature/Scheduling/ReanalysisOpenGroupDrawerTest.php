<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Events\NewGroupPlacementRequested;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use Dom\Element;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Abrir un grupo» en el drawer de sugerencias del re-análisis (tarea 8.3b): el jefe busca con el motor el horario y el
| salón del grupo nuevo al abrir su tarjeta (nunca al pintar), puede elegir otro salón libre y lo aplica. Subdirección lo
| ve sin buscar ni aplicar; el coordinador y el jefe de otro departamento no. El mundo: Cálculo Integral en C2 con ISIC
| faltante (120 esperados contra el grupo A de 40 lugares, que ya no crece más que a 50).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
    $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 120]);
    $this->openKey = "open_group:{$this->world->isc->id}";
    $this->searches = 0;
    Event::listen(NewGroupPlacementRequested::class, function (): void {
        $this->searches++;
    });
});

function openGroupDrawer(User $user, ReanalysisWorld $world): Testable
{
    return Livewire::actingAs($user)
        ->test('scheduling::components.reanalysis-suggestions', ['loadId' => $world->load->id])
        ->dispatch('open-reanalysis-suggestions', subjectId: $world->integral->id, campusId: $world->c2->id);
}

function openGroupCard(Testable $drawer, string $key): ?Element
{
    return domOf($drawer)->querySelector("[data-key=\"{$key}\"]");
}

/**
 * El lugar que encontró el motor y guardó el drawer (el único buscado).
 */
function openGroupPlacementIn(Testable $drawer): NewGroupPlacement
{
    return NewGroupPlacement::fromArray(array_values($drawer->get('placements'))[0]['placement']);
}

function integralGroupsInC2(ReanalysisWorld $world): int
{
    return Group::query()->where('subject_id', $world->integral->id)->where('campus_id', $world->c2->id)->count();
}

it('el jefe busca el horario y el salón una sola vez, al abrir la tarjeta, y aplica «Abrir un grupo»', function () {
    $drawer = openGroupDrawer($this->jefe, $this->world);

    expect($this->searches)->toBe(0)
        ->and(textOf(openGroupCard($drawer, $this->openKey)->querySelector('[data-testid="suggestion-placement"]')))->toBe('Lo busca el motor con los demás grupos de la carga fijos. Buscando lugar…');

    $drawer->call('findPlacement', $this->openKey);
    $card = openGroupCard($drawer, $this->openKey);
    $placement = openGroupPlacementIn($drawer);

    expect(textOf($card->querySelector('[data-testid="suggestion-schedule"]')))->toBe('Lun y Mar 9:00–10:00')
        ->and(textOf($card->querySelector('[data-testid="suggestion-room"]')))->toBe($placement->roomCode($placement->roomIds()[0]).'·50 lugares')
        ->and(textOf($card->querySelector('[data-testid="suggestion-evidence"]')))->toContain('Puntaje blando que agrega: 0')
        ->and($card->querySelector('[data-testid="suggestion-apply"]'))->not->toBeNull()
        ->and($card->querySelector('[data-testid="suggestion-find"]'))->toBeNull();

    $drawer->call('discard', "expand_quota:{$this->world->groups['A']->id}:{$this->world->isc->id}")->call('cancelEdit');

    expect($this->searches)->toBe(1)
        ->and(openGroupCard($drawer, $this->openKey)->querySelector('[data-testid="suggestion-schedule"]'))->not->toBeNull();

    $drawer->call('apply', $this->openKey)->assertDispatched('reanalysis-adjusted');
    $group = Group::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->latest('id')->first();

    expect(lastToastOf($drawer)['title'])->toBe('Grupo abierto')
        ->and(lastToastOf($drawer)['description'])->toContain("El grupo {$group->code} de ACF-0902 para ISIC: Lun y Mar 9:00–10:00. Asígnale docente en Asignación docente.")
        ->and($group->teacher_id)->toBeNull()
        ->and(GroupQuota::query()->where('group_id', $group->id)->pluck('max_seats', 'career_id')->all())->toBe([$this->world->isc->id => 35])
        ->and(GroupSession::query()->where('group_id', $group->id)->count())->toBe(2)
        ->and($this->searches)->toBe(1)
        ->and($drawer->get('placements'))->toBe([]);
});

it('«Editar» cambia el salón del grupo nuevo por otro libre de la lista', function () {
    $large = Room::factory()->capacity(60)->create(['building_id' => Building::factory()->create(['campus_id' => $this->world->c2->id])->id, 'code' => 'L-60']);
    $drawer = openGroupDrawer($this->jefe, $this->world)->call('findPlacement', $this->openKey)->call('edit', $this->openKey);

    expect($drawer->get('roomOptions'))->toHaveKey($large->id)
        ->and(openGroupCard($drawer, $this->openKey)->querySelector('[data-testid="suggestion-room-select"]'))->not->toBeNull();

    $drawer->set('editRoomId', 'otro')->call('apply', $this->openKey)->assertHasErrors(['editRoomId' => 'in']);

    expect(integralGroupsInC2($this->world))->toBe(2);

    $drawer->set('editRoomId', $large->id)->call('apply', $this->openKey)->assertHasNoErrors();
    $group = Group::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->latest('id')->first();

    expect(GroupSession::query()->where('group_id', $group->id)->pluck('room_id')->unique()->all())->toBe([$large->id]);
});

it('sin lugar posible lo dice, con la regla, y no ofrece aplicar', function () {
    Room::query()->where('campus_id', $this->world->c2->id)->update(['capacity' => 30]);

    $drawer = openGroupDrawer($this->jefe, $this->world)->call('findPlacement', $this->openKey)
        ->assertSee('El motor no le encontró lugar con los demás grupos fijos.')
        ->assertSee('HC-05');
    $card = openGroupCard($drawer, $this->openKey);

    expect($card->querySelector('[data-testid="suggestion-apply"]'))->toBeNull()
        ->and(textOf($card->querySelector('[data-testid="suggestion-find"]')))->toBe('Buscar otra vez');

    $drawer->call('apply', $this->openKey);

    expect(lastToastOf($drawer)['description'])->toBe('El motor no le encontró lugar al grupo nuevo: no hay qué aplicar.')
        ->and(integralGroupsInC2($this->world))->toBe(2);
});

it('si el salón se ocupó antes de aplicar, no abre nada, lo dice y pide buscar otra vez', function () {
    $drawer = openGroupDrawer($this->jefe, $this->world)->call('findPlacement', $this->openKey);
    $session = openGroupPlacementIn($drawer)->sessions[0];
    $other = $this->world->group($this->world->differential, $this->world->iind, 20, $this->world->c2, 1, day: 5);
    GroupSession::factory()->for($other)->inRoom(Room::query()->whereKey($session['roomId'])->sole())->on($session['day'], $session['startsAt'], $session['endsAt'])->create();

    $drawer->call('apply', $this->openKey);

    expect(lastToastOf($drawer)['title'])->toBe('No se aplicó la sugerencia')
        ->and(lastToastOf($drawer)['description'])->toStartWith('Esto cambió desde que buscaste el lugar:')
        ->and(integralGroupsInC2($this->world))->toBe(2)
        ->and(openGroupCard($drawer, $this->openKey)->querySelector('[data-testid="suggestion-find"]'))->not->toBeNull();
});

it('con la carga fuera de re-análisis no busca ni aplica', function () {
    $drawer = openGroupDrawer($this->jefe, $this->world)->call('findPlacement', $this->openKey);
    $this->world->load->update(['status' => LoadStatus::Closed]);

    $drawer->call('apply', $this->openKey);

    expect(lastToastOf($drawer)['description'])->toBe('La carga ya no está en re-análisis: las sugerencias ya no se aplican.')
        ->and(integralGroupsInC2($this->world))->toBe(2);
});

describe('quien no ajusta (RA-07)', function () {
    it('Subdirección ve la tarjeta de solo consulta y no busca ni aplica', function () {
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
        $drawer = openGroupDrawer($subdireccion, $this->world);

        expect(openGroupCard($drawer, $this->openKey)->querySelector('[data-testid="suggestion-find"]')->hasAttribute('disabled'))->toBeTrue();

        openGroupDrawer($subdireccion, $this->world)->call('findPlacement', $this->openKey)->assertForbidden();
        openGroupDrawer($subdireccion, $this->world)->call('apply', $this->openKey)->assertForbidden();

        expect($this->searches)->toBe(0)
            ->and(integralGroupsInC2($this->world))->toBe(2);
    });

    it('el coordinador y el jefe de otro departamento no buscan ni aplican sobre el drawer del jefe', function (string $who) {
        $actor = $who === 'coordinador'
            ? $this->world->coordinator($this->world->isc)
            : User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();
        $finding = openGroupDrawer($this->jefe, $this->world);
        $applying = openGroupDrawer($this->jefe, $this->world)->call('findPlacement', $this->openKey);
        $this->actingAs($actor);

        $finding->call('findPlacement', $this->openKey)->assertForbidden();
        $applying->call('apply', $this->openKey)->assertForbidden();

        expect(integralGroupsInC2($this->world))->toBe(2);
    })->with(['coordinador', 'otro jefe']);
});
