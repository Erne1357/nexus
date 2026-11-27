<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| El cajón del grupo de «Carga actual» (tarea 1.61, SEL-18): sesiones y salones con las Actions y el validador del
| editor de horarios, y el docente con el motivo de HC-16 y la búsqueda de HC-21. Edita sesiones el jefe; asigna docente
| el jefe y el JPD.
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->isc = $this->world->career('ISC');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->room = Room::factory()->for(Building::factory()->create(['campus_id' => $this->world->campus->id]))->create(['code' => '703', 'capacity' => 40]);

    $this->d = $this->world->group($this->poo, $this->isc, 'D', 2, [[1, '10:00', '11:00'], [3, '10:00', '11:00']], ['capacity' => 25]);
    $this->teachers = collect(['Ana Ruiz', 'Beto Salas', 'Carla Díaz'])->map(fn (string $name) => $this->world->teacher($name));
    $this->world->rank($this->d, $this->teachers->all());

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    $this->proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id])->create();
});

function loadDrawer(User $user, bool $open = true): Testable
{
    onCampusPage(test()->world->campus);
    $drawer = Livewire::actingAs($user)->test('staffing::components.current-load-drawer', [
        'termId' => test()->world->term->id,
        'modalityId' => modalityOf('PRES')->id,
        'departmentId' => test()->world->department->id,
    ]);

    return $open ? $drawer->call('open', test()->d->id) : $drawer;
}

describe('apertura', function () {
    it('SEL-18: abre con la clave, la materia, las sesiones del grupo y sus choques duros', function () {
        ScheduleConflict::factory()->forGroup($this->d)->create(['rule' => 'HC-01', 'message' => 'Docente ocupado.']);

        loadDrawer($this->jefe)
            ->assertSet('show', true)
            ->assertSeeText(['SCC-1013', 'grupo D', 'ISC', 'Sem. 2', 'Programación Orientada a Objetos'])
            ->assertSeeHtml('data-testid="sessions-list"')
            ->assertSeeText(['Lunes', 'Miércoles', '10:00'])
            ->assertSeeHtml('data-testid="load-drawer-conflicts"')
            ->assertSeeText('HC-01');
    });

    it('el jefe de otro departamento no abre un grupo ajeno en el cajón (404)', function () {
        $cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
        $jefeCb = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $cb->id])->create();

        loadDrawer($jefeCb, open: false)->call('open', $this->d->id)->assertForbidden();
    });
});

describe('sesiones y salones', function () {
    it('HC-03: el jefe cambia el horario de una sesión y queda guardado', function () {
        $session = $this->d->sessions()->orderBy('day')->first();

        $drawer = loadDrawer($this->jefe)
            ->call('edit', $session->id)
            ->set('startsAt', '11:00')->set('endsAt', '12:00')
            ->call('saveSession')
            ->assertHasNoErrors()
            ->assertDispatched('current-load-changed');

        expect(substr((string) $session->fresh()->starts_at, 0, 5))->toBe('11:00')
            ->and(lastToastOf($drawer))->type->toBe('success');
    });

    it('HC-04: lo que el validador rechaza se ve en el cajón y no se guarda', function () {
        $session = $this->d->sessions()->orderBy('day')->first();

        $drawer = loadDrawer($this->jefe)
            ->call('edit', $session->id)
            ->set('startsAt', '12:00')->set('endsAt', '11:00')
            ->call('saveSession')
            ->assertSeeHtml('data-testid="session-error"');

        expect(substr((string) $session->fresh()->starts_at, 0, 5))->toBe('10:00')
            ->and(lastToastOf($drawer))->type->toBe('error');
    });

    it('HC-01: una sesión que empalma al docente del grupo se rechaza y no cambia nada', function () {
        $other = $this->world->group($this->poo, $this->isc, 'E', 2, [[2, '10:00', '11:00']]);
        $this->d->update(['teacher_id' => $this->teachers[0]->id]);
        $other->update(['teacher_id' => $this->teachers[0]->id]);
        $session = $this->d->sessions()->where('day', 1)->first();

        loadDrawer($this->jefe)
            ->call('edit', $session->id)
            ->set('day', '2')
            ->call('saveSession')
            ->assertSeeHtml('data-testid="session-error"');

        expect($session->fresh()->day)->toBe(1);
    });

    it('el jefe agrega y quita una sesión', function () {
        $drawer = loadDrawer($this->jefe)
            ->call('startAdding')
            ->set('day', '5')->set('startsAt', '16:00')->set('endsAt', '17:00')
            ->call('addSession')
            ->assertHasNoErrors();

        $added = $this->d->sessions()->where('day', 5)->sole();
        expect($this->d->sessions()->count())->toBe(3);

        $drawer->call('removeSession', $added->id);
        expect($this->d->sessions()->count())->toBe(2);
    });

    it('el jefe elige el salón de una sesión con la sección de salón del editor', function () {
        $session = $this->d->sessions()->orderBy('day')->first();

        loadDrawer($this->jefe)
            ->call('toggleRoom', $session->id)
            ->assertSeeHtml('data-testid="session-room"');

        Livewire::actingAs($this->jefe)->test('scheduling::components.session-room', ['sessionId' => $session->id])
            ->call('assign', $this->room->id)
            ->assertDispatched('session-room-saved');

        expect($session->fresh()->room_id)->toBe($this->room->id);
    });

    it('el JPD ve las sesiones de solo lectura y llamar los métodos es 403', function () {
        $session = $this->d->sessions()->orderBy('day')->first();

        loadDrawer($this->proyecto)
            ->assertSeeHtml('data-testid="sessions-reason"')
            ->assertDontSeeHtml('data-testid="edit-session"')
            ->call('edit', $session->id)->assertSet('editing', $session->id)
            ->call('saveSession')->assertForbidden();

        loadDrawer($this->proyecto)->call('startAdding')->assertForbidden();
        loadDrawer($this->proyecto)->call('removeSession', $session->id)->assertForbidden();
        expect($this->d->sessions()->count())->toBe(2);
    });

    it('con la carga que no acepta ediciones el jefe la ve de solo lectura con el motivo', function () {
        $this->world->load->update(['status' => LoadStatus::Published]);

        loadDrawer($this->jefe)->assertSeeHtml('data-testid="sessions-reason"')->assertDontSeeHtml('data-testid="add-session"');
    });
});

describe('docente', function () {
    it('HC-16: el primer lugar del ranking se asigna sin motivo', function () {
        $drawer = loadDrawer($this->jefe)
            ->call('setTab', 'docente')
            ->assertSeeHtml('data-testid="ranking-list"')
            ->call('assign', $this->teachers[0]->id)
            ->assertDispatched('current-load-changed');

        $assignment = GroupTeacherAssignment::query()->current()->where('group_id', $this->d->id)->sole();

        expect($this->d->fresh()->teacher_id)->toBe($this->teachers[0]->id)
            ->and($assignment->reason)->toBeNull()
            ->and(lastToastOf($drawer))->type->toBe('success');
    });

    it('HC-16: otro lugar del ranking pide el motivo y no asigna sin él', function () {
        loadDrawer($this->jefe)
            ->call('setTab', 'docente')
            ->call('assign', $this->teachers[2]->id)
            ->assertSeeHtml('data-testid="reason-form"')
            ->call('confirmAssign')->assertHasErrors(['reason' => 'required'])
            ->set('reason', 'Coordina el laboratorio.')
            ->call('confirmAssign')->assertHasNoErrors();

        $assignment = GroupTeacherAssignment::query()->current()->where('group_id', $this->d->id)->sole();
        expect($this->d->fresh()->teacher_id)->toBe($this->teachers[2]->id)
            ->and($assignment->reason)->toBe('Coordina el laboratorio.')
            ->and($assignment->ranking_position_at_assignment)->toBe(3);
    });

    it('HC-21: un docente buscado entra por el mismo flujo, con motivo', function () {
        $outsider = $this->world->teacher('Zoila Fuera');

        loadDrawer($this->jefe)
            ->dispatch('teacher-picked', groupId: $this->d->id, teacherId: $outsider->id)
            ->assertSet('pending.teacher', $outsider->id)
            ->set('reason', 'Lo pidió el jefe de otro departamento.')
            ->call('confirmAssign');

        expect($this->d->fresh()->teacher_id)->toBe($outsider->id);
    });

    it('el JPD asigna docente desde el cajón', function () {
        loadDrawer($this->proyecto)->call('setTab', 'docente')->call('assign', $this->teachers[0]->id);

        expect($this->d->fresh()->teacher_id)->toBe($this->teachers[0]->id);
    });

    it('el jefe quita el docente con motivo', function () {
        $this->world->assign($this->d, $this->teachers[0], $this->jefe);

        loadDrawer($this->jefe)
            ->call('askUnassign')->set('unassignReason', 'Cambio de plan.')->call('unassign')
            ->assertHasNoErrors();

        expect($this->d->fresh()->teacher_id)->toBeNull();
    });

    it('con la carga en borrador no se asigna y el cajón lo dice', function () {
        $this->world->load->update(['status' => LoadStatus::Draft]);

        loadDrawer($this->jefe)->call('setTab', 'docente')->assertSeeHtml('data-testid="teacher-reason"')->call('assign', $this->teachers[0]->id)
            ->assertHasErrors();

        expect($this->d->fresh()->teacher_id)->toBeNull();
    });

    it('el coordinador y el docente no pueden usar el cajón', function () {
        $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->world->campus->id, 'career_id' => $this->isc->id])->create();

        loadDrawer($coordinador, open: false)->call('open', $this->d->id)->assertForbidden();
        loadDrawer($coordinador, open: false)->call('assign', $this->teachers[0]->id)->assertForbidden();
        expect($this->d->fresh()->teacher_id)->toBeNull();
    });
});
