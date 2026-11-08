<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\AssignmentWorld;

/*
| «Asignación docente» (tarea 3.9): el jefe asigna el docente de cada grupo de su departamento con el ranking a la
| vista; fuera del primer lugar escribe el motivo (HC-16). Dos vistas: «Por materia» (`?vista=materia`, la de
| entrada) y «Dos paneles» (`?vista=paneles`).
*/

beforeEach(function () {
    $this->world = AssignmentWorld::create();
    $this->isc = $this->world->career('ISC');
    $this->poo = $this->world->subject('SCC-1013', 'Programación Orientada a Objetos');
    $this->redes = $this->world->subject('SCD-1021', 'Redes de Computadoras');

    $this->open = $this->world->group($this->poo, $this->isc, 'D', 2, [[1, '10:00', '11:00'], [3, '10:00', '11:00']]);
    $this->done = $this->world->group($this->poo, $this->isc, 'G', 2, [[2, '13:00', '15:00']]);
    $this->network = $this->world->group($this->redes, $this->isc, 'H', 5, [[5, '16:00', '18:00']]);

    $this->teachers = collect(['Ana Ruiz', 'Beto Salas', 'Carla Díaz', 'Dante Ortiz'])->map(fn (string $name) => $this->world->teacher($name));
    foreach ([$this->open, $this->done, $this->network] as $group) {
        $this->world->rank($group, $this->teachers->all());
    }

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create(['name' => 'Rosa Villalobos']);
    $this->world->assign($this->done, $this->teachers[1], $this->jefe, position: 2, size: 4, reason: 'Pidió conservar el grupo.');
});

/**
 * @param  array<string, string>  $query
 */
function assignPage(User $user, array $query = []): Testable
{
    test()->withSession([Actor::ACTIVE_CAMPUS => test()->world->campus->id]);

    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.assign');
}

describe('vista por materia', function () {
    it('muestra cada materia con sus grupos, su avance y los tres primeros lugares de los grupos sin docente', function () {
        assignPage($this->jefe)
            ->assertOk()
            ->assertSeeHtml('data-testid="assign-by-subject"')
            ->assertSeeInOrder(['Asignación docente', 'Sistemas y Computación', 'Campus II', 'Ene-Jun 2027', 'Asignación'])
            ->assertSeeText('1 de 3 con docente')
            ->assertSeeInOrder(['Programación Orientada a Objetos', 'SCC-1013', 'L/Mi 10:00–11:00', 'Ana Ruiz', 'Beto Salas', 'Carla Díaz', 'Beto Salas', 'Rosa Villalobos', 'Redes de Computadoras', 'SCD-1021'])
            ->assertSeeText(['1 de 2 con docente', '0 de 1 con docente', 'Lugar 2 de 4'])
            ->assertSeeHtml('12 → 10')
            ->assertDontSee('Dante Ortiz');
    });

    it('«Ver el ranking completo» lo abre en su lugar con el historial', function () {
        assignPage($this->jefe)
            ->assertSeeText('Ver el ranking completo (4)')
            ->call('toggleRanking', $this->done->id)
            ->assertSee('Dante Ortiz')
            ->assertSee('Historial de asignaciones')
            ->assertSee('Pidió conservar el grupo.')
            ->call('toggleRanking', $this->done->id)
            ->assertDontSee('Dante Ortiz');
    });

    it('asigna al primer lugar con un clic y avisa con su lugar', function () {
        $page = assignPage($this->jefe)->call('assign', $this->open->id, $this->teachers[0]->id, 'tarjetas');

        $assignment = GroupTeacherAssignment::query()->current()->where('group_id', $this->open->id)->sole();

        expect($this->open->fresh()->teacher_id)->toBe($this->teachers[0]->id)
            ->and($assignment->ranking_position_at_assignment)->toBe(1)
            ->and($assignment->reason)->toBeNull()
            ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Asignaste a Ana Ruiz')
            ->and(lastToastOf($page)['description'])->toContain('Lugar 1 de 4');
        $page->assertDontSeeHtml('data-testid="reason-box"');
    });

    it('HC-16: fuera del primer lugar pide el motivo con su lugar y no asigna sin él', function () {
        assignPage($this->jefe)
            ->call('assign', $this->open->id, $this->teachers[2]->id, 'tarjetas')
            ->assertSeeHtml('data-testid="reason-box"')
            ->assertSeeText('Ocupa el lugar 3 de 4')
            ->call('confirmAssign')
            ->assertHasErrors(['reason' => 'required']);

        expect($this->open->fresh()->teacher_id)->toBeNull();
    });

    it('HC-16: con el motivo asigna al tercer lugar y guarda «3 de 4» y el motivo', function () {
        $page = assignPage($this->jefe)
            ->call('assign', $this->open->id, $this->teachers[2]->id, 'tarjetas')
            ->set('reason', 'Coordina el laboratorio de la materia.')
            ->call('confirmAssign')
            ->assertHasNoErrors()
            ->assertDontSeeHtml('data-testid="reason-box"');

        $assignment = GroupTeacherAssignment::query()->current()->where('group_id', $this->open->id)->sole();

        expect($assignment->teacher_id)->toBe($this->teachers[2]->id)
            ->and($assignment->ranking_position_at_assignment)->toBe(3)
            ->and($assignment->ranking_size_at_assignment)->toBe(4)
            ->and($assignment->reason)->toBe('Coordina el laboratorio de la materia.')
            ->and(lastToastOf($page)['description'])->toContain('Lugar 3 de 4');
    });

    it('«Cancelar» cierra el motivo sin asignar', function () {
        assignPage($this->jefe)
            ->call('assign', $this->open->id, $this->teachers[3]->id)
            ->call('cancelReason')
            ->assertDontSeeHtml('data-testid="reason-box"');

        expect($this->open->fresh()->teacher_id)->toBeNull();
    });

    it('HC-01: el choque que rechaza la Action se ve junto al docente y no asigna', function () {
        $busy = Group::factory()->create(['term_id' => $this->world->term->id, 'campus_id' => $this->world->campus->id, 'teacher_id' => $this->teachers[0]->id, 'code' => 'Z']);
        GroupSession::factory()->for($busy)->on(1, '10:00', '11:00')->create();

        $page = assignPage($this->jefe)->call('assign', $this->open->id, $this->teachers[0]->id, 'tarjetas');

        expect($this->open->fresh()->teacher_id)->toBeNull()
            ->and(lastToastOf($page)['type'])->toBe('error');
        $page->assertSeeHtml('data-testid="assign-failure"');
    });

    it('«Cambiar» abre el ranking del grupo con docente y marca al asignado', function () {
        assignPage($this->jefe)
            ->assertSee('Cambiar')
            ->call('toggleRanking', $this->done->id)
            ->assertSeeHtml('data-testid="current-teacher"');
    });

    it('?grupo= abre el ranking de ese grupo y lo deja para desplazarse a él', function () {
        assignPage($this->jefe, ['grupo' => $this->network->id])
            ->assertSee('Dante Ortiz')
            ->assertSeeHtml('data-scroll-to="'.$this->network->id.'"');
    });

    it('los filtros dejan solo los grupos que los cumplen', function () {
        assignPage($this->jefe)
            ->set('state', 'con')
            ->assertSeeHtml('data-subject="SCC-1013"')
            ->assertDontSeeHtml('data-subject="SCD-1021"')
            ->set('state', 'todos')
            ->set('search', 'redes')
            ->assertDontSeeHtml('data-subject="SCC-1013"')
            ->assertSeeHtml('data-subject="SCD-1021"')
            ->set('search', '')
            ->set('semesterFilter', '2')
            ->assertDontSeeHtml('data-subject="SCD-1021"');
    });
});

describe('vista de dos paneles', function () {
    it('?vista=paneles muestra la lista de grupos y el primero sin docente con su ranking', function () {
        assignPage($this->jefe, ['vista' => 'paneles'])
            ->assertSeeHtml('data-testid="assign-panels"')
            ->assertSeeHtml('data-testid="group-list"')
            ->assertSeeHtml('data-selected-group="'.$this->open->id.'"')
            ->assertSee('Dante Ortiz');
    });

    it('?grupo= elige ese grupo y la pestaña Historial muestra quién asignó, el lugar y el motivo', function () {
        assignPage($this->jefe, ['vista' => 'paneles', 'grupo' => $this->done->id])
            ->assertSeeHtml('data-selected-group="'.$this->done->id.'"')
            ->call('setTab', 'historial')
            ->assertSeeHtmlInOrder(['data-testid="history-row"', 'Rosa Villalobos', 'Beto Salas', '2 de 4', 'Pidió conservar el grupo.', 'Vigente']);
    });

    it('elegir otro grupo lo abre y la vista elegida se recuerda en la sesión', function () {
        assignPage($this->jefe, ['vista' => 'paneles'])
            ->call('pick', $this->network->id)
            ->assertSet('groupId', $this->network->id)
            ->assertSet('sheet', true)
            ->call('setView', 'materia');

        assignPage($this->jefe)->assertSet('view', 'materia');
        assignPage($this->jefe)->call('setView', 'paneles');
        assignPage($this->jefe)->assertSet('view', 'paneles')->assertSeeHtml('data-testid="assign-panels"');
    });

    it('HC-16: desde el ranking pide el motivo en el renglón del docente', function () {
        assignPage($this->jefe, ['vista' => 'paneles', 'grupo' => $this->open->id])
            ->call('assign', $this->open->id, $this->teachers[1]->id, 'ranking')
            ->assertSeeHtml('data-testid="reason-box"')
            ->assertSeeText('Ocupa el lugar 2 de 4');
    });
});

describe('estado de la carga', function () {
    it('en propuesta avisa que se asigna en Asignación y el jefe la pasa a asignación docente', function () {
        $this->world->load->update(['status' => LoadStatus::Proposed]);

        $page = assignPage($this->jefe)
            ->assertSeeHtml('data-testid="load-banner"')
            ->assertSee('Pasar a asignación docente')
            ->assertDontSeeHtml('data-testid="assign-button"')
            ->call('startStaffing');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Staffing)
            ->and(lastToastOf($page)['type'])->toBe('success');
        $page->assertSeeHtml('data-testid="assign-button"');
    });

    it('si Scheduling veta el paso a asignación muestra los motivos y la carga sigue en propuesta', function () {
        $this->world->load->update(['status' => LoadStatus::Proposed]);
        Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->block('Hay 2 grupos sin horario.'));

        assignPage($this->jefe)
            ->call('startStaffing')
            ->assertSeeHtml('data-testid="veto-reasons"')
            ->assertSee('Hay 2 grupos sin horario.');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Proposed);
    });

    it('el jefe de proyecto docente ve la carga en propuesta sin el botón para pasarla y no puede pasarla', function () {
        $this->world->load->update(['status' => LoadStatus::Proposed]);
        $proyecto = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id])->create();

        assignPage($proyecto)
            ->assertSeeHtml('data-testid="load-banner"')
            ->assertDontSee('Pasar a asignación docente')
            ->call('startStaffing')
            ->assertForbidden();
    });

    it('en borrador o cerrada es de solo lectura con su estado y no asigna', function (LoadStatus $status, string $message) {
        $this->world->load->update(['status' => $status]);

        $page = assignPage($this->jefe)
            ->assertSee($message)
            ->assertDontSeeHtml('data-testid="assign-button"')
            ->assertDontSee('Cambiar')
            ->call('assign', $this->open->id, $this->teachers[0]->id);

        expect($this->open->fresh()->teacher_id)->toBeNull()
            ->and(lastToastOf($page)['type'])->toBe('error');
    })->with([
        'borrador' => [LoadStatus::Draft, 'La carga está en Borrador'],
        'cerrada' => [LoadStatus::Closed, 'La carga está en Cerrada'],
    ]);
});

describe('ranking por calcular', function () {
    it('dice «Calculando el ranking…» y consulta cada 5 segundos mientras falta', function () {
        DB::table('staffing_rankings')->update(['computed_at' => now()->subHour()]);
        $this->world->group($this->redes, $this->isc, 'K', 5, [[4, '18:00', '20:00']]);

        assignPage($this->jefe)
            ->assertSee('Calculando el ranking…')
            ->assertSeeHtml('wire:poll.5s');
    });

    it('con todos los rankings listos no consulta', function () {
        assignPage($this->jefe)->assertDontSeeHtml('wire:poll');
    });
});

it('lee la pantalla con las mismas consultas sin importar cuántos grupos tenga', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withSession([Actor::ACTIVE_CAMPUS => $this->world->campus->id])->actingAs($this->jefe)->get(route('staffing.assign'))->assertOk();

        return count(DB::getQueryLog());
    };
    $count();
    $before = $count();

    foreach (range(1, 6) as $i) {
        $group = $this->world->group($this->redes, $this->world->career("C{$i}"), "M{$i}", 5, [[$i % 5 + 1, sprintf('%02d:00', 7 + $i), sprintf('%02d:00', 8 + $i)]]);
        $this->world->rank($group, $this->teachers->all());
    }

    expect($count())->toBe($before);
});
