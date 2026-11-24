<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\SelectionWorld;

/*
| «Oferta» en la orden de selección (tarea 1.33, diseño 6.4 a 6.7): con una selección publicada o en curso de su
| departamento, el docente ve el horario por hora (maqueta A) con su turno, su cuenta de horas y el orden en un drawer;
| en su turno toma y suelta grupos desde el drawer del grupo y termina su carga. Fuera de su turno es de solo lectura.
| La jefatura asigna a mano cualquier grupo a cualquier hora. Nombres ficticios.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create();
    $this->uno = selectionOfferTeacher($this->world->teacher(base: 5, name: 'Docente Uno'));
    $this->dos = selectionOfferTeacher($this->world->teacher(base: 5, slotStartsAt: CarbonImmutable::now()->addMinutes(25), position: 2, name: 'Docente Dos'));
    $this->group = $this->world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);
    $this->room = $this->world->room('A1');
});

/**
 * El docente de la cola con su puesto de docente, que le da «Mi espacio › Oferta».
 */
function selectionOfferTeacher(Teacher $teacher): Teacher
{
    RoleAssignment::factory()->for($teacher->user)->create(['role' => Role::Docente]);

    return $teacher;
}

function selectionDrawer(User $user): Testable
{
    return Livewire::actingAs($user)->test('staffing::components.selection-group-drawer', ['selectionId' => test()->world->selection->id]);
}

it('fuera de su turno el horario es de solo lectura', function () {
    Livewire::actingAs($this->dos->user)->test('staffing::pages.offer')
        ->assertOk()
        ->assertSeeHtml('data-testid="selection-timetable"')
        ->assertSee('En turno: Docente Uno')
        ->assertSee('Solo consulta hasta tu turno')
        ->assertDontSeeHtml('data-testid="selection-finish"');

    selectionDrawer($this->dos->user)
        ->call('open', $this->group->id)
        ->assertSee('Solo consulta')
        ->assertDontSeeHtml('data-testid="selection-take"')
        ->set('roomId', $this->room->id)
        ->call('take')
        ->assertHasErrors(['group' => 'No es tu turno.']);

    expect($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->count())->toBe(0);
});

it('en su turno toma un grupo desde el drawer', function () {
    Livewire::actingAs($this->uno->user)->test('staffing::pages.offer')
        ->assertSee('Es tu turno')
        ->assertSeeHtml('data-testid="selection-finish"')
        ->assertSeeHtml('data-testid="selection-card-'.$this->group->id.'"');

    selectionDrawer($this->uno->user)
        ->call('open', $this->group->id)
        ->assertSee('Tomar en A1')
        ->call('take')
        ->assertHasNoErrors()
        ->assertDispatched('selection-changed')
        ->assertSet('show', false);

    expect($this->group->fresh()->teacher_id)->toBe($this->uno->id)
        ->and(GroupSession::query()->where('group_id', $this->group->id)->pluck('room_id')->unique()->all())->toBe([$this->room->id]);
});

it('en su turno suelta un grupo que tomó', function () {
    selectionDrawer($this->uno->user)->call('open', $this->group->id)->call('take');

    selectionDrawer($this->uno->user)
        ->call('open', $this->group->id)
        ->assertSeeHtml('data-testid="selection-release"')
        ->call('release')
        ->assertHasNoErrors()
        ->assertDispatched('selection-changed');

    expect($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupSession::query()->where('group_id', $this->group->id)->whereNotNull('room_id')->count())->toBe(0);
});

it('elige el salón con el evento room-picked del mapa, por su map_key', function () {
    $this->world->room('A2')->update(['map_key' => 'edificio-a-a2']);

    selectionDrawer($this->uno->user)
        ->call('open', $this->group->id)
        ->set('roomTab', 'mapa')
        ->call('chooseRoomByMapKey', 'edificio-a-a2')
        ->assertSee('Tomar en A2')
        ->call('chooseRoomByMapKey', 'no-existe')
        ->assertSee('Tomar en A2');
});

it('Review Focus 5: al recargar a mitad del turno conserva lo tomado y la hora de fin', function () {
    selectionDrawer($this->uno->user)->call('open', $this->group->id)->call('take');
    $endsAt = $this->world->entryOf($this->uno)->slot_ends_at;

    $this->travel(10)->minutes();

    $page = Livewire::actingAs($this->uno->user)->test('staffing::pages.offer');
    $countdown = domOf($page->html())->querySelector('[data-testid="selection-countdown"]');
    $card = domOf($page->html())->querySelector('[data-testid="selection-card-'.$this->group->id.'"]');

    expect($countdown->getAttribute('data-ends-at'))->toBe($endsAt->toIso8601String())
        ->and(trim($countdown->textContent))->toBe('15:00')
        ->and(preg_replace('/\s+/u', ' ', $card->textContent))->toContain('Tú')->toContain('A1');
});

it('todos ven el desglose de criterios del orden', function () {
    $this->world->entryOf($this->uno)->update(['auto_eval' => false, 'failed_criteria' => 1, 'tier' => 1]);

    $page = Livewire::actingAs($this->dos->user)->test('staffing::pages.offer')
        ->set('showOrder', true)
        ->assertSeeHtml('data-testid="selection-order"');
    $row = domOf($page->html())->querySelector('[data-testid="order-row-1"]');

    expect(preg_replace('/\s+/u', ' ', $row->textContent))
        ->toContain('Docente Uno')
        ->toContain('Autoevaluación: no cumple')
        ->toContain('Cumplimiento de asignación previa: cumple')
        ->toContain('TC → ¾');
});

it('un docente de otro departamento no ve el horario de selección', function () {
    $other = Teacher::factory()->create(['name' => 'Docente Ajeno', 'user_id' => User::factory()->create()->id]);
    TeacherDepartment::factory()->create(['teacher_id' => $other->id, 'department_id' => Department::factory()->create()->id]);
    selectionOfferTeacher($other);

    Livewire::actingAs($other->user)->test('staffing::pages.offer')
        ->assertOk()
        ->assertDontSeeHtml('data-testid="selection-timetable"')
        ->assertDontSee('Orden de selección');

    selectionDrawer($other->user)->assertForbidden();
});

it('después de terminar su carga ya no toma grupos aunque siga su media hora', function () {
    $this->world->entryOf($this->uno)->update(['status' => SelectionEntryStatus::Done, 'closed_at' => now()]);

    Livewire::actingAs($this->uno->user)->test('staffing::pages.offer')
        ->assertSee('Terminaste tu carga')
        ->assertDontSeeHtml('data-testid="selection-finish"');

    selectionDrawer($this->uno->user)
        ->call('open', $this->group->id)
        ->assertDontSeeHtml('data-testid="selection-take"')
        ->call('take')
        ->assertHasErrors('group');

    expect($this->group->fresh()->teacher_id)->toBeNull();
});

it('terminar mi carga fuera del margen pide la casilla y avisa del grupo sin salón', function () {
    $this->world->entryOf($this->uno)->update(['base_hours' => 2]);
    $lab = $this->world->group('L', [[2, '09:00', '10:00']], space: RequiredSpace::Laboratorio);
    selectionDrawer($this->uno->user)->call('open', $lab->id)->call('take')->assertHasNoErrors();

    $page = Livewire::actingAs($this->uno->user)->test('staffing::pages.offer')
        ->set('showFinish', true)
        ->assertSee('Este grupo queda sin salón; lo asigna la jefatura')
        ->assertSee('Es responsabilidad del docente cubrir sus horas de acuerdo con su base')
        ->call('finish')
        ->assertHasErrors('acknowledged');

    expect($this->world->entryOf($this->uno)->status)->toBe(SelectionEntryStatus::Waiting);

    $page->set('acknowledged', true)->call('finish')->assertHasNoErrors()->assertSet('showFinish', false);

    expect($this->world->entryOf($this->uno)->fresh()->status)->toBe(SelectionEntryStatus::Done);
});

it('el jefe asigna a mano fuera de turno', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();

    Livewire::actingAs($jefe)->test('staffing::pages.offer')
        ->assertOk()
        ->assertSee('Asignas cualquier grupo, a cualquier hora.')
        ->assertSee('Asignar a…')
        ->assertDontSeeHtml('data-testid="selection-finish"');

    selectionDrawer($jefe)
        ->call('open', $this->group->id)
        ->assertSet('mode', 'assign')
        ->assertSee('Docente Dos')
        ->set('teacherId', $this->dos->id)
        ->set('roomId', $this->room->id)
        ->call('assign')
        ->assertHasNoErrors()
        ->assertDispatched('selection-changed');

    expect($this->group->fresh()->teacher_id)->toBe($this->dos->id)
        ->and(GroupTeacherAssignment::query()->current()->where('group_id', $this->group->id)->sole())
        ->assigned_by->toBe($jefe->id)
        ->reason->toBe(AssignTeacher::SELECTION_REASON)
        ->and(GroupSession::query()->where('group_id', $this->group->id)->pluck('room_id')->unique()->all())->toBe([$this->room->id]);
});

it('el docente no asigna a otro con el drawer de la jefatura', function () {
    selectionDrawer($this->uno->user)
        ->call('open', $this->group->id)
        ->set('teacherId', $this->dos->id)
        ->call('assign')
        ->assertForbidden();

    expect($this->group->fresh()->teacher_id)->toBeNull();
});

it('el jefe de otro departamento no ve la selección ni asigna en ella', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();

    Livewire::actingAs($jefe)->test('staffing::pages.offer')->assertForbidden();
    selectionDrawer($jefe)->assertForbidden();

    expect($this->group->fresh()->teacher_id)->toBeNull();
});
