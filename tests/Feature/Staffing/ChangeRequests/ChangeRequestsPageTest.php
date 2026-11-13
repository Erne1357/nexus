<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequested;
use App\Modules\Staffing\Queries\ChangeRequestsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/ChangeRequestScreensSupport.php';

/*
| «Solicitudes de cambio» (tarea 3.11, maqueta A «Lista y detalle»): la lista del departamento a la izquierda (pendientes,
| luego aprobadas por aplicar, luego el resto) y el detalle a la derecha. El jefe aprueba o rechaza; aplica el
| coordinador de la carrera dueña si es de horario y el jefe si es de salón (confirmado el 7 de noviembre de 2026).
*/

beforeEach(function () {
    $this->w = crWorld();
    $this->g1 = crGroup($this->w, '1A', 'Fundamentos de Programación', $this->w->sc, $this->w->isc, $this->w->teacher);
    $this->g2 = crGroup($this->w, '5A', 'Bases de Datos', $this->w->sc, $this->w->iind, $this->w->otherTeacher);
});

/**
 * @param  array<string, string>  $query
 */
function crPage(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.change-requests');
}

/**
 * Los ids de las solicitudes de la lista, en orden.
 *
 * @return list<string>
 */
function crListedIds(Testable $page): array
{
    preg_match_all('/data-request="([0-9a-f-]+)"/', $page->html(), $matches);

    return $matches[1];
}

/**
 * Los ids de los grupos que ofrece el select de «Nueva solicitud».
 *
 * @return list<string>
 */
function crGroupOptions(Testable $page): array
{
    preg_match_all('/data-group-option="([0-9a-f-]+)"/', $page->html(), $matches);

    return $matches[1];
}

/**
 * Un grupo de la carrera ISC en un tercer departamento cuya carga está en `$status`.
 */
function crWorldDepartmentGroup(object $world, LoadStatus $status): Group
{
    $department = Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']);
    AcademicLoad::factory()->create(['term_id' => $world->term->id, 'modality_id' => $world->campus->modality_id, 'department_id' => $department->id, 'status' => $status]);

    return crGroup($world, '9Z', 'Materia en propuesta', $department, $world->isc, null);
}

describe('lista', function () {
    it('ordena pendientes, luego aprobadas por aplicar, luego el resto; las más recientes primero', function () {
        $applied = crRequest($this->g1, $this->w->teacherUser, 'applied', ['created_at' => now()->subDays(1)]);
        $approved = crRequest($this->g2, $this->w->otherTeacherUser, 'approved', ['created_at' => now()->subDays(5)]);
        $oldPending = crRequest($this->g1, $this->w->teacherUser, 'pending', ['created_at' => now()->subDays(4)]);
        $rejected = crRequest($this->g2, $this->w->otherTeacherUser, 'rejected', ['created_at' => now()->subHours(2)]);
        $newPending = crRequest($this->g2, $this->w->coordIind, 'pending', ['created_at' => now()->subDays(2)]);

        expect(crListedIds(crPage($this->w->jefeSc)))->toBe([$newPending->id, $oldPending->id, $approved->id, $rejected->id, $applied->id]);
    });

    it('cada renglón dice quién la pide, el grupo, el estado, el tipo y lo que propone', function () {
        crRequest($this->g1, $this->w->teacherUser, 'pending', ['proposed' => ['description' => 'Martes y jueves de 10 a 12']]);

        crPage($this->w->jefeSc)
            ->assertSeeInOrder(['Marisol Quiñones Duarte', 'Fundamentos de Programación', '1A', 'ISC', 'Pendiente', 'Horario', 'Martes y jueves de 10 a 12']);
    });

    it('filtra por estado, por tipo y por texto sin importar acentos', function () {
        $pending = crRequest($this->g1, $this->w->teacherUser, 'pending');
        $room = crRequest($this->g2, $this->w->otherTeacherUser, 'approved', ['kind' => 'room']);
        $rejected = crRequest($this->g2, $this->w->otherTeacherUser, 'rejected');

        $page = crPage($this->w->jefeSc);

        expect(crListedIds($page->set('status', 'aprobadas')))->toBe([$room->id])
            ->and(crListedIds($page->set('status', '')->set('kind', 'horario')))->toBe([$pending->id, $rejected->id])
            ->and(crListedIds($page->set('kind', '')->set('search', 'ivan gutierrez')))->toBe([$room->id, $rejected->id])
            ->and(crListedIds($page->set('search', 'fundamentos')))->toBe([$pending->id])
            ->and(crListedIds($page->set('search', '5a')))->toBe([$room->id, $rejected->id]);
    });

    it('cuenta las solicitudes de cada estado en los filtros', function () {
        crRequest($this->g1, $this->w->teacherUser, 'pending');
        crRequest($this->g2, $this->w->otherTeacherUser, 'pending');
        crRequest($this->g2, $this->w->coordIind, 'approved');

        crPage($this->w->jefeSc)
            ->assertSeeHtml('data-testid="status-pendientes"')
            ->assertSeeInOrder(['Todas', '3', 'Pendientes', '2', 'Aprobadas', '1', 'Rechazadas', '0', 'Aplicadas', '0']);
    });

    it('sin solicitudes en el departamento lo dice', function () {
        crPage($this->w->jefeSc)->assertSee('No hay solicitudes de cambio en este departamento.');
    });

    it('la consulta de la lista hace un número constante de consultas', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ChangeRequestsQuery::class)->listFor(app(Actor::class), $this->w->term->id, $this->w->campus->id, $this->w->sc->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->actingAs($this->w->jefeSc);
        crRequest($this->g1, $this->w->teacherUser, 'applied');
        $count();
        $few = $count();

        foreach (range(1, 6) as $n) {
            $group = crGroup($this->w, "G{$n}", "Materia {$n}", $this->w->sc, $n % 2 === 0 ? $this->w->isc : $this->w->iind, $this->w->otherTeacher);
            crRequest($group, $n % 2 === 0 ? $this->w->otherTeacherUser : $this->w->coordIsc, ['pending', 'approved', 'rejected', 'applied'][$n % 4]);
        }

        expect($count())->toBe($few)->toBeLessThanOrEqual(8);
    });

    it('la página completa hace un número constante de consultas', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            crPage($this->w->jefeSc);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        crRequest($this->g1, $this->w->teacherUser, 'applied');
        $count();
        $few = $count();

        foreach (range(1, 8) as $n) {
            $group = crGroup($this->w, "G{$n}", "Materia {$n}", $this->w->sc, $this->w->isc, $this->w->otherTeacher);
            crRequest($group, $this->w->otherTeacherUser, ['pending', 'approved', 'rejected', 'applied'][$n % 4]);
        }

        expect($count())->toBe($few);
    });
});

describe('detalle', function () {
    it('?solicitud= abre esa solicitud con docente, grupo, tipo, la foto contra lo propuesto, motivo e historial', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'rejected', [
            'proposed' => ['description' => 'Martes y jueves de 10 a 12'],
            'reason' => 'Atiendo residencias los lunes.',
            'decided_by' => $this->w->jefeSc->id,
            'decision_comment' => 'Ese bloque está ocupado.',
        ]);
        crRequest($this->g2, $this->w->otherTeacherUser, 'pending');

        crPage($this->w->jefeSc, ['solicitud' => $request->id])
            ->assertSet('requestId', $request->id)
            ->assertSeeHtml('data-testid="request-detail"')
            ->assertSeeInOrder([
                'Fundamentos de Programación', '1A', 'Rechazada', 'Horario',
                'Docente', 'Marisol Quiñones Duarte',
                'Carrera dueña', 'ISC', 'Ingeniería en Sistemas Computacionales', 'Campus II',
                'Al pedirla', 'Lu Mi', '7:00–9:00', 'Propuesta', 'Martes y jueves de 10 a 12',
                'Motivo', 'Atiendo residencias los lunes.',
                'Historial', 'Pidió', 'Marisol Quiñones Duarte', 'Rechazó', 'Alfredo Ríos Carrasco', 'Ese bloque está ocupado.',
            ]);
    });

    it('sin ?solicitud= abre la primera de la lista', function () {
        crRequest($this->g2, $this->w->otherTeacherUser, 'approved');
        $pending = crRequest($this->g1, $this->w->teacherUser, 'pending');

        crPage($this->w->jefeSc)->assertSeeHtml('data-testid="request-detail" data-request-id="'.$pending->id.'"');
    });

    it('elegir una solicitud de la lista la abre', function () {
        crRequest($this->g1, $this->w->teacherUser, 'pending');
        $other = crRequest($this->g2, $this->w->otherTeacherUser, 'approved');

        crPage($this->w->jefeSc)
            ->call('pick', $other->id)
            ->assertSet('requestId', $other->id)
            ->assertSet('mobileDetail', true)
            ->assertSeeHtml('data-testid="request-detail" data-request-id="'.$other->id.'"');
    });

    it('muestra el estado de la carga del departamento en el aviso', function () {
        crPage($this->w->jefeSc)
            ->assertSeeHtml('data-testid="load-banner"')
            ->assertSeeInOrder(['Sistemas y Computación', 'Campus II', 'Asignación']);

        $this->w->scLoad->update(['status' => LoadStatus::Adjusting]);
        crRequest($this->g1, $this->w->teacherUser, 'approved');

        crPage($this->w->jefeSc)->assertSeeInOrder(['Ajustes', '1 aprobada por aplicar']);
    });

    it('al coordinador le dice cuántas por aplicar hay en el departamento y cuántas son de su carrera', function () {
        $this->w->scLoad->update(['status' => LoadStatus::Adjusting]);
        crRequest($this->g1, $this->w->teacherUser, 'approved');
        crRequest($this->g2, $this->w->otherTeacherUser, 'approved');
        crRequest($this->g2, $this->w->coordIind, 'approved', ['kind' => 'room']);

        crPage($this->w->coordIsc)->assertSee('3 aprobadas por aplicar en el departamento · 1 de tu carrera.');
        crPage($this->w->jefeSc)->assertSee('3 aprobadas por aplicar en el departamento. Al aplicarlas')->assertDontSee('de tu carrera');
    });
});

describe('decidir', function () {
    it('el jefe aprueba con un comentario opcional y la carga pasa a Ajustes', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'pending');

        crPage($this->w->jefeSc, ['solicitud' => $request->id])
            ->assertSeeHtml('data-testid="approve-request"')
            ->set('comment', 'De acuerdo.')
            ->call('approve')
            ->assertHasNoErrors()
            ->assertSee('Aprobada');

        $request->refresh();
        expect($request->status)->toBe(ChangeRequestStatus::Approved)
            ->and($request->decided_by)->toBe($this->w->jefeSc->id)
            ->and($request->decision_comment)->toBe('De acuerdo.')
            ->and($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Adjusting);
    });

    it('el jefe rechaza con comentario obligatorio', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'pending');
        $page = crPage($this->w->jefeSc, ['solicitud' => $request->id]);

        $page->set('comment', '  ')->call('reject')->assertHasErrors(['comment'])->assertSee('Para rechazar, escribe un comentario.');
        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);

        $page->set('comment', 'Ese bloque está ocupado.')->call('reject')->assertHasNoErrors();

        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Rejected)
            ->and($request->fresh()->decision_comment)->toBe('Ese bloque está ocupado.')
            ->and($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Staffing);
    });

    it('el coordinador no decide: no ve los botones y la llamada se rechaza', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'pending');

        crPage($this->w->coordIsc, ['solicitud' => $request->id])
            ->assertDontSeeHtml('data-testid="approve-request"')
            ->assertSee('La revisa el jefe del departamento.')
            ->call('approve')
            ->assertForbidden();

        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
    });

    it('una solicitud que ya no está pendiente no se decide', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved');

        crPage($this->w->jefeSc, ['solicitud' => $request->id])
            ->assertDontSeeHtml('data-testid="approve-request"')
            ->call('approve')
            ->assertHasErrors(['status']);
    });
});

describe('aplicar', function () {
    beforeEach(function () {
        $this->w->scLoad->update(['status' => LoadStatus::Adjusting]);
    });

    it('el coordinador de la carrera dueña marca aplicada una de horario y la última regresa la carga a Asignación', function () {
        // El veto real de Scheduling (BlockStaffingWithHardConflicts) tiene sus pruebas; aquí se aísla la pantalla.
        Event::forget(LoadTransitioning::class);
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved');

        crPage($this->w->coordIsc, ['solicitud' => $request->id])
            ->assertSeeHtml('data-testid="apply-request"')
            ->call('markApplied')
            ->assertHasNoErrors();

        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Applied)
            ->and($request->fresh()->applied_by)->toBe($this->w->coordIsc->id)
            ->and($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Staffing);
    });

    it('con otra aprobada sin aplicar, la carga se queda en Ajustes', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved');
        crRequest($this->g2, $this->w->otherTeacherUser, 'approved', ['kind' => 'room']);

        crPage($this->w->coordIsc, ['solicitud' => $request->id])->call('markApplied');

        expect($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Adjusting);
    });

    it('el coordinador no aplica una de salón', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved', ['kind' => 'room']);

        crPage($this->w->coordIsc, ['solicitud' => $request->id])->assertNotFound();
        expect(Gate::forUser($this->w->coordIsc)->allows('apply', $request))->toBeFalse();
    });

    it('el jefe no aplica una de horario: dice quién la aplica', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved');

        crPage($this->w->jefeSc, ['solicitud' => $request->id])
            ->assertDontSeeHtml('data-testid="apply-request"')
            ->assertSee('La aplica la coordinación de ISC en el editor de horarios.')
            ->call('markApplied')
            ->assertForbidden();
    });

    it('el jefe marca aplicada una de salón', function () {
        // El veto real de Scheduling (BlockStaffingWithHardConflicts) tiene sus pruebas; aquí se aísla la pantalla.
        Event::forget(LoadTransitioning::class);
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved', ['kind' => 'room']);

        crPage($this->w->jefeSc, ['solicitud' => $request->id])
            ->assertSeeHtml('data-testid="apply-request"')
            ->call('markApplied')
            ->assertHasNoErrors();

        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Applied)
            ->and($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Staffing);
    });

    it('si Scheduling veta el regreso, muestra los motivos y la carga sigue en Ajustes', function () {
        // El veto real de Scheduling (BlockStaffingWithHardConflicts) tiene sus pruebas; aquí se aísla la pantalla.
        Event::forget(LoadTransitioning::class);
        Event::listen(LoadTransitioning::class, function (LoadTransitioning $event): void {
            if ($event->to === LoadStatus::Staffing) {
                $event->block('Hay 2 choques duros en el horario.');
            }
        });
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved');

        crPage($this->w->coordIsc, ['solicitud' => $request->id])
            ->call('markApplied')
            ->assertSeeHtml('data-testid="veto-reasons"')
            ->assertSee('La carga sigue en Ajustes: Hay 2 choques duros en el horario.');

        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Applied)
            ->and($this->w->scLoad->fresh()->status)->toBe(LoadStatus::Adjusting);
    });

    it('«Abrir en el editor» lleva al editor de horarios con el grupo, junto a «Marcar como aplicada»', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved');

        crPage($this->w->coordIsc, ['solicitud' => $request->id])
            ->assertSeeHtml('data-testid="open-editor"')
            ->assertSeeHtml('href="'.e(route('scheduling.editor', ['campus' => $this->w->campus->code, 'grupo' => $this->g1->id])).'"');

        crPage($this->w->jefeSc, ['solicitud' => $request->id])->assertDontSeeHtml('data-testid="open-editor"');
    });
});

describe('nueva solicitud (coordinador, confirmado el 7 de noviembre de 2026)', function () {
    it('el coordinador pide un cambio en un grupo de su carrera desde «Nueva solicitud» y queda abierta en la lista', function () {
        $page = crPage($this->w->coordIsc)
            ->assertSeeHtml('data-testid="new-request"')
            ->call('openRequest')
            ->assertSet('showRequest', true)
            ->assertSee('Fundamentos de Programación')
            ->set('requestGroupId', $this->g1->id)
            ->set('requestKind', 'room')
            ->set('proposal', 'Un laboratorio de cómputo')
            ->set('reason', 'Las prácticas necesitan equipo.')
            ->call('sendRequest')
            ->assertHasNoErrors()
            ->assertSet('showRequest', false);

        $request = ChangeRequest::query()->sole();

        expect($request->group_id)->toBe($this->g1->id)
            ->and($request->requested_by)->toBe($this->w->coordIsc->id)
            ->and($request->teacher_id)->toBeNull()
            ->and($request->kind)->toBe(ChangeRequestKind::Room)
            ->and($request->status)->toBe(ChangeRequestStatus::Pending);

        $page->assertSet('requestId', $request->id)
            ->assertSeeHtml('data-testid="request-detail" data-request-id="'.$request->id.'"');
    });

    it('solo ofrece los grupos no cancelados de su carrera, del campus y periodo, con la carga en Asignación o Ajustes', function () {
        $cancelled = crGroup($this->w, '1C', 'Materia cancelada', $this->w->sc, $this->w->isc, null);
        $cancelled->update(['status' => 'cancelled']);
        $other = crWorldDepartmentGroup($this->w, LoadStatus::Proposed);

        $page = crPage($this->w->coordIsc)->call('openRequest');
        $options = crGroupOptions($page);

        expect($options)->toContain($this->g1->id)
            ->not->toContain($this->g2->id)
            ->not->toContain($cancelled->id)
            ->not->toContain($other->id);
    });

    it('el coordinador de otra carrera no puede elegir ese grupo aunque mande su id', function () {
        crPage($this->w->coordIind)
            ->call('openRequest')
            ->set('requestGroupId', $this->g1->id)
            ->set('proposal', 'Martes de 10 a 12')
            ->set('reason', 'Motivo')
            ->call('sendRequest')
            ->assertNotFound();

        expect(crGroupOptions(crPage($this->w->coordIind)->call('openRequest')))->not->toContain($this->g1->id)
            ->and(ChangeRequest::query()->count())->toBe(0);
    });

    it('el grupo, lo que propone y el motivo son obligatorios', function () {
        crPage($this->w->coordIsc)
            ->call('openRequest')
            ->call('sendRequest')
            ->assertHasErrors(['requestGroupId' => 'required', 'proposal' => 'required', 'reason' => 'required'])
            ->assertSee('Elige el grupo.');
    });

    it('sin grupos que se puedan pedir, el botón queda deshabilitado con su motivo', function () {
        $this->w->scLoad->update(['status' => LoadStatus::Closed]);

        crPage($this->w->coordIsc)
            ->assertSeeHtml('data-testid="new-request" disabled')
            ->assertSee('No hay grupos de tu carrera con la carga en Asignación o Ajustes.');
    });

    it('el docente, el jefe y la Subdirección no ven «Nueva solicitud» ni pueden abrirla', function (string $who) {
        crRequest($this->g1, $this->w->teacherUser, 'pending');
        $user = $who === 'subdireccion' ? User::factory()->withRole(Role::Subdireccion)->create() : $this->w->{$who};

        crPage($user)->assertDontSeeHtml('data-testid="new-request"')->call('openRequest')->assertForbidden();
    })->with(['teacherUser', 'jefeSc', 'subdireccion']);
});

describe('alcance', function () {
    it('el jefe de otro departamento no ve las solicitudes ni puede abrir una', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'pending');

        crPage($this->w->jefeCb)->assertDontSeeHtml('data-request="'.$request->id.'"')->assertDontSee('Marisol Quiñones Duarte');
        crPage($this->w->jefeCb, ['solicitud' => $request->id])->assertNotFound();
        crPage($this->w->jefeCb, ['depto' => 'SC'])->assertForbidden();
    });

    it('el jefe de otro departamento no puede decidir aunque llame con el id', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'pending');

        crPage($this->w->jefeCb)->call('pick', $request->id)->assertNotFound();
        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
    });

    it('el coordinador ve solo las de horario de su carrera', function () {
        $mine = crRequest($this->g1, $this->w->teacherUser, 'approved');
        $room = crRequest($this->g1, $this->w->teacherUser, 'rejected', ['kind' => 'room']);
        $otherCareer = crRequest($this->g2, $this->w->otherTeacherUser, 'approved');

        expect(crListedIds(crPage($this->w->coordIsc)))->toBe([$mine->id]);
        crPage($this->w->coordIsc, ['solicitud' => $otherCareer->id])->assertNotFound();
        crPage($this->w->coordIsc, ['solicitud' => $room->id])->assertNotFound();
        expect(crListedIds(crPage($this->w->coordIind)))->toBe([$otherCareer->id]);
    });

    it('otro coordinador no puede aplicar la de una carrera ajena', function () {
        $this->w->scLoad->update(['status' => LoadStatus::Adjusting]);
        $request = crRequest($this->g1, $this->w->teacherUser, 'approved');
        crRequest($this->g2, $this->w->otherTeacherUser, 'pending');

        crPage($this->w->coordIind)->call('pick', $request->id)->assertNotFound();
        expect($request->fresh()->status)->toBe(ChangeRequestStatus::Approved);
    });

    it('el docente ve solo las suyas, sin botones de decidir ni aplicar', function () {
        $mine = crRequest($this->g1, $this->w->teacherUser, 'pending');
        $theirs = crRequest($this->g2, $this->w->otherTeacherUser, 'pending');

        expect(crListedIds(crPage($this->w->teacherUser)))->toBe([$mine->id]);
        crPage($this->w->teacherUser, ['solicitud' => $theirs->id])->assertNotFound();
        crPage($this->w->teacherUser, ['solicitud' => $mine->id])
            ->assertDontSeeHtml('data-testid="approve-request"')
            ->call('approve')
            ->assertForbidden();
    });

    it('la Subdirección y el jefe de proyecto docente ven sin decidir ni aplicar', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'pending');
        $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->w->campus->id, 'department_id' => $this->w->sc->id])->create();
        $sub = User::factory()->withRole(Role::Subdireccion)->create();

        foreach ([$jpd, $sub] as $viewer) {
            crPage($viewer, ['solicitud' => $request->id])
                ->assertSeeHtml('data-request="'.$request->id.'"')
                ->assertDontSeeHtml('data-testid="approve-request"');
        }
    });

    it('un departamento pedido por ?depto= que no ve es 403', function () {
        crPage($this->w->jefeSc, ['depto' => 'CB'])->assertForbidden();
    });

    it('quien no pide, ve, decide ni aplica no entra', function () {
        $this->get(route('staffing.change-requests'))->assertRedirect();
        crPage(User::factory()->create())->assertForbidden();
    });
});

describe('menú', function () {
    it('la entrada «Solicitudes de cambio» va en Académico para quien ve, decide o aplica', function (string $who, bool $sees) {
        $items = collect(app(Navigation::class)->visibleTo(app(Actor::class)->forUser($this->w->{$who})))->keyBy('label');

        expect($items->has('Solicitudes de cambio'))->toBe($sees);

        if ($sees) {
            expect($items['Solicitudes de cambio']->area)->toBe(NavigationArea::Academico)
                ->and($items['Solicitudes de cambio']->route)->toBe('staffing.change-requests');
        }
    })->with([
        'jefe' => ['jefeSc', true],
        'coordinador' => ['coordIsc', true],
        'docente' => ['teacherUser', false],
    ]);
});

describe('avisos', function () {
    it('el aviso a los jefes dice el tipo de cambio y abre la pantalla con la solicitud', function () {
        $request = crRequest($this->g1, $this->w->teacherUser, 'pending', ['kind' => 'room']);
        $message = (new ChangeRequested($request))->message($this->w->jefeSc);

        expect($message->title)->toBe('Solicitud de cambio de salón')
            ->and(route($message->route, $message->parameters))->toBe(route('staffing.change-requests', ['solicitud' => $request->id]))
            ->and(ChangeRequested::unavailableReason(app(Actor::class)->forUser($this->w->jefeSc), $message))->toBeNull();
    });
});
