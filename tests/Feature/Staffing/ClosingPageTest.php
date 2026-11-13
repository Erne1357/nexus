<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\ClosingWorld;

/*
| «Cierre de la carga» (tarea 4.1; maqueta B2, elegida por el usuario el 10 de noviembre de 2026): el jefe ve la carga
| de su departamento por campus, lo que le falta para cerrar, las justificaciones y el historial, y la cierra con
| confirmación; Subdirección también cierra y reabre con motivo (confirmado el 10 de noviembre de 2026). Lo que vetan
| las guardas se ve en palabras.
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    $this->jefe = $this->world->head();
});

/**
 * @param  array<string, string>  $query
 */
function closingPage(User $user, array $query = []): Testable
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('staffing::pages.closing');
}

describe('lo que se ve en cada estado', function () {
    it('en asignación con faltantes: cuántos faltan, «Cerrar carga» deshabilitado y la pantalla donde se arregla', function () {
        $this->world->group();
        $this->world->group(teacher: false);
        $this->world->group(withRoom: false);

        closingPage($this->jefe)
            ->assertSee('Cierre de la carga')
            ->assertSee('Sistemas y Computación')
            ->assertSee('Presencial')
            ->assertSee('Faltan 2 grupos para cerrar')
            ->assertSee('1 sin docente · 1 sin salón')
            ->assertSeeHtml('data-testid="condition-teacher"')
            ->assertSee('Ir a Asignación docente')
            ->assertSee('Ir a Horarios')
            ->assertSeeHtml('data-testid="action-close"')
            ->assertSeeHtml('title="Faltan 2 grupos para cerrar."')
            ->assertDontSeeHtml('data-testid="modality-tabs"');
    });

    it('lista para cerrar: la línea lo dice y «Cerrar carga» está activo', function () {
        $this->world->group();
        $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $this->jefe);

        $page = closingPage($this->jefe)
            ->assertSee('Lista para cerrar')
            ->assertSee('2 grupos activos · 1 justificado');

        expect(closingActionDisabled($page, 'close'))->toBeFalse();
    });

    it('cerrada: «Reabrir carga» deshabilitado con su motivo escrito y la publicación debajo de la línea de estados', function () {
        $this->world->load->update(['status' => LoadStatus::Closed]);
        $this->world->group();
        $this->world->transition(LoadStatus::Staffing, LoadStatus::Closed, $this->jefe, 'Todo asignado', now()->setDate(2026, 12, 7));

        $page = closingPage($this->jefe)
            ->assertSee('Cerrada el 07/12/2026')
            ->assertSee('Por Laura Ramos · falta publicarla')
            ->assertSee('Solo Subdirección reabre la carga.')
            ->assertDontSee('La publicación llega con la tarea 4.3.')
            ->assertDontSeeHtml('data-testid="action-publish"')
            ->assertSeeLivewire('academic-load::components.publish-load')
            ->assertSee('Todo asignado');

        expect(closingActionDisabled($page, 'reopen'))->toBeTrue()
            ->and(domOf($page)->querySelector('[data-testid="load-publication"] [data-testid="publish-load"]'))->not->toBeNull();
    });

    it('con la carga cerrada, el jefe y Subdirección ven la publicación', function (string $who) {
        $this->world->load->update(['status' => LoadStatus::Closed]);
        $this->world->group();
        $user = $who === 'jefe' ? $this->jefe : User::factory()->withRole(Role::Subdireccion)->create();

        closingPage($user, ['departamento' => $this->world->department->id])
            ->assertSeeLivewire('academic-load::components.publish-load')
            ->assertSee('Publicar');
    })->with(['jefe', 'Subdirección']);

    it('al publicar (`load-published`) vuelve a leer la carga: la línea de estados, la insignia y lo que falta', function () {
        $this->world->load->update(['status' => LoadStatus::Closed]);
        $this->world->group();
        $this->world->transition(LoadStatus::Staffing, LoadStatus::Closed, $this->jefe);
        $page = closingPage($this->jefe)->assertSee('Cerrada el');

        $this->world->load->update(['status' => LoadStatus::Published, 'current_version' => 1]);
        $page->dispatch('load-published', loadId: $this->world->load->id, version: 1)
            ->assertSet('blockers', null)
            ->assertSee('Publicada · v1')
            ->assertSee('La carga ya pasó el cierre. Para cambiarla, Subdirección la reabre.');
    });

    it('publicada: con su versión y sin calcular lo que falta', function () {
        $this->world->load->update(['status' => LoadStatus::Published, 'current_version' => 2]);
        $this->world->group(teacher: false);

        closingPage($this->jefe)
            ->assertSee('Publicada · v2')
            ->assertSee('La carga ya pasó el cierre. Para cambiarla, Subdirección la reabre.')
            ->assertSeeLivewire('academic-load::components.publish-load');
    });

    it('reabierta: dice quién y cuándo la reabrió, con su motivo, y ofrece «Volver a asignación»', function () {
        $this->world->load->update(['status' => LoadStatus::Adjusting]);
        $this->world->group();
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Héctor Salinas']);
        $this->world->transition(LoadStatus::Closed, LoadStatus::Adjusting, $subdireccion, 'Falta el grupo vespertino de Cálculo', now()->setDate(2026, 12, 9));

        $page = closingPage($this->jefe)
            ->assertSee('Reabierta el 09/12/2026')
            ->assertSee('Para cerrarla otra vez, vuelve a asignación.')
            ->assertSee('Falta el grupo vespertino de Cálculo')
            ->assertSee('Héctor Salinas');

        expect(closingActionDisabled($page, 'return'))->toBeFalse()
            ->and($page->html())->not->toContain('data-testid="action-close"');
    });

    it('con el visto bueno apagado (todos los campus hoy) no ofrece aprobar', function () {
        $this->world->load->update(['status' => LoadStatus::Closed]);
        $this->world->group();

        closingPage($this->jefe)->assertDontSeeHtml('data-testid="action-approve"');
    });

    it('en un campus que exige el visto bueno, la carga cerrada lo espera y Subdirección la aprueba', function () {
        modalityOf('PRES')->update(['settings' => [...ModalityProfile::DEFAULTS, 'requires_subdireccion_approval' => true]]);
        $this->world->load->update(['status' => LoadStatus::Closed]);
        $this->world->group();
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        closingPage($this->jefe)->assertSee('espera el visto bueno de Subdirección')->assertSee('Da el visto bueno Subdirección.');

        closingPage($subdireccion, ['departamento' => $this->world->department->id])
            ->call('askApprove')
            ->set('approveComment', 'Revisada')
            ->call('approve')
            ->assertHasNoErrors();

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Approved);
    });
});

describe('modalidad y filtro de campus (M1; maqueta U2, opción A)', function () {
    it('MS16: con dos modalidades, el select «Modalidad» cambia de carga, sin pestañas', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $ead = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $published = $this->world->loadIn($ead, LoadStatus::Published, modality: modalityOf('EAD'));
        $published->update(['current_version' => 1]);
        $this->world->group();
        $this->world->group(load: $published);

        $page = closingPage($this->jefe, ['modalidad' => 'PRES'])
            ->assertSeeHtml('data-testid="modality-scope"')
            ->assertDontSeeHtml('data-testid="modality-tabs"')
            ->assertSee('Lista para cerrar');

        expect(domOf($page)->querySelector('[data-testid="modality-scope"] option[value="PRES"]')?->hasAttribute('selected'))->toBeTrue();

        $page->set('modalidad', 'EAD')
            ->assertSet('modalidad', 'EAD')
            ->assertSee('Publicada · v1')
            ->assertSee('La carga ya pasó el cierre.');
    });

    it('MS30: sin elegir, con EAD activa y sin grupos del departamento en ella, abre en la modalidad de su carga, y elegir EAD deja el select para volver', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $this->world->group();

        closingPage($this->jefe)->assertSet('modalidad', 'PRES')->assertSee('Lista para cerrar');
        closingPage($this->jefe, ['modalidad' => 'EAD'])
            ->assertSee('Sistemas y Computación no tiene grupos en EAD en Ene-Jun 2027')
            ->assertSeeHtml('data-testid="modality-scope"');
    });

    it('una modalidad inactiva o ajena en la URL se ignora: abre la carga de la modalidad que trabaja', function () {
        $this->world->group();

        closingPage($this->jefe, ['modalidad' => 'ENL'])->assertSet('modalidad', 'PRES')->assertSee('Lista para cerrar');

        // Ajena: EAD activa, pero el nombramiento es solo de Presencial.
        modalityOf('EAD')->update(['is_active' => true]);
        vacate(Role::JefeDepartamento, $this->world->department->id);
        $presencial = User::factory()->withRole(Role::JefeDepartamento, ['modality_id' => modalityOf('PRES')->id, 'department_id' => $this->world->department->id, 'valid_from' => today()->toDateString()])->create();

        closingPage($presencial, ['modalidad' => 'EAD'])->assertSet('modalidad', 'PRES')->assertSee('Lista para cerrar')->assertDontSeeHtml('data-testid="modality-scope"');
    });

    it('MS6: el filtro ofrece los campus de la modalidad con los grupos de la carga en cada uno, y dice que el cierre es de toda la carga', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $this->world->group(load: $this->world->loadIn($c1));
        $this->world->group(load: $this->world->loadIn($c1));
        $this->world->group();

        $dom = domOf(closingPage($this->jefe));

        expect(textOf($dom->querySelector('[data-testid="campus-count-todos"]')))->toBe('3')
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C1"]')))->toBe('2')
            ->and(textOf($dom->querySelector('[data-testid="campus-count-C2"]')))->toBe('1')
            ->and(textOf($dom->querySelector('[data-testid="closing-campus"]')))->toContain('Filtra lo que falta y las justificaciones; el cierre es de toda la carga.')
            ->and(textOf($dom->querySelector('[data-testid="closing-campus-split"]')))->toBe('Una sola carga: 2 grupos en C1 y 1 en C2. Se cierra y se publica una vez.');
    });

    it('U2: sin filtro, cada cifra de grupos de «Para cerrar» lleva su reparto por campus y los trámites no', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $this->world->group(teacher: false, load: $this->world->loadIn($c1));
        $this->world->group(teacher: false);
        $this->world->group(teacher: false);
        ChangeRequest::factory()->forGroup($this->world->group())->create();

        $dom = domOf(closingPage($this->jefe));
        $teacher = $dom->querySelector('[data-testid="condition-teacher-by-campus"]');

        expect(textOf($dom->querySelector('[data-testid="condition-teacher"]')))->toStartWith('3')
            ->and(preg_replace('/\s+/', ' ', textOf($teacher)))->toBe('C1Campus I 1 C2Campus II 2')
            ->and($dom->querySelector('[data-testid="condition-requests-by-campus"]'))->toBeNull()
            ->and(textOf($dom->querySelector('[data-testid="missing-badge"]')))->toBe('Faltan 3 grupos y 1 trámite');
    });

    it('U2: con el filtro de campus, «Para cerrar» cuenta los grupos de ese campus y los trámites de toda la carga; el estado y el cierre siguen siendo de toda la carga', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $this->world->group(teacher: false, load: $this->world->loadIn($c1));
        $this->world->group(teacher: false);
        $this->world->group(teacher: false);
        ChangeRequest::factory()->forGroup($this->world->group())->create();

        $page = closingPage($this->jefe, ['campus' => 'C2']);
        $dom = domOf($page);

        expect(textOf($dom->querySelector('[data-testid="condition-teacher"]')))->toStartWith('2')
            ->and($dom->querySelector('[data-testid="condition-teacher-by-campus"]'))->toBeNull()
            ->and(textOf($dom->querySelector('[data-testid="condition-requests"]')))->toStartWith('1')
            ->and(textOf($dom->querySelector('[data-testid="missing-badge"]')))->toBe('Faltan 2 grupos y 1 trámite');

        $page->assertSee('Faltan 3 grupos y 1 trámite para cerrar')->assertSeeHtml('title="Faltan 3 grupos y 1 trámite para cerrar."');

        expect(textOf(domOf(closingPage($this->jefe, ['campus' => 'C1']))->querySelector('[data-testid="condition-teacher"]')))->toStartWith('1');
    });

    it('U2: un campus del filtro al que no le falta nada no dice «Lista para cerrar» si a la carga le falta algo en otro', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $this->world->group(load: $this->world->loadIn($c1));
        $this->world->group(teacher: false);

        $dom = domOf(closingPage($this->jefe, ['campus' => 'C1']));

        expect(textOf($dom->querySelector('[data-testid="missing-badge"]')))->toBe('Nada falta en C1')
            ->and(textOf($dom->querySelector('[data-testid="condition-teacher"]')))->not->toStartWith('1');
    });

    it('MS4: con una modalidad sin salones no hay filtro de campus', function () {
        Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        modalityOf('PRES')->update(['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false]]);
        $this->world->group(withRoom: false);

        closingPage($this->jefe)->assertSee('Lista para cerrar')->assertDontSeeHtml('data-testid="campus-filter"')->assertDontSeeHtml('data-testid="closing-campus"');
    });

    it('un ?campus[]= que no es texto es «Todos», sin error', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $inC1 = $this->world->group(teacher: false, load: $this->world->loadIn($c1));
        $inC2 = $this->world->group(teacher: false);

        $this->actingAs($this->jefe)->get(route('staffing.closing').'?campus[]=C1')
            ->assertOk()
            ->assertSee('data-testid="page-item-'.$inC1->code.'"', false)
            ->assertSee('data-testid="page-item-'.$inC2->code.'"', false);
    });

    it('M1: la carga presencial junta los grupos de C1 y C2; el campus solo filtra la lista de justificaciones', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $inC1 = $this->world->group(teacher: false, load: $this->world->loadIn($c1));
        $inC2 = $this->world->group(teacher: false);
        $item = fn (Group $group): string => 'data-testid="page-item-'.$group->code.'"';

        closingPage($this->jefe)
            ->assertDontSeeHtml('data-testid="modality-tabs"')
            ->assertSee('Faltan 2 grupos para cerrar');

        $this->actingAs($this->jefe)->get(route('staffing.closing', ['campus' => 'C1']))
            ->assertOk()->assertSee('Faltan 2 grupos para cerrar')
            ->assertSee($item($inC1), false)->assertDontSee($item($inC2), false);
        $this->get(route('staffing.closing'))
            ->assertOk()->assertSee($item($inC1), false)->assertSee($item($inC2), false);

        closingPage($this->jefe)->set('campus', 'C1')->assertSet('campus', 'C1');

        expect(session('core.last_campus'))->toBe('C1');
    });

    it('«Todos» quita el filtro de campus sin error y la lista vuelve a los grupos de C1 y C2', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $this->world->group(teacher: false, load: $this->world->loadIn($c1));
        $this->world->group(teacher: false);

        $page = closingPage($this->jefe, ['campus' => 'C1'])
            ->assertSeeHtml('data-testid="campus-filter-todos"')
            ->set('campus', null)
            ->assertOk()
            ->assertSet('campus', null)
            ->assertSee('Faltan 2 grupos para cerrar');

        expect(Livewire::actingAs($this->jefe)->test('staffing::components.closing-justifications', ['loadId' => $this->world->load->id, 'campusId' => null])->viewData('total'))->toBe(2)
            ->and(Livewire::actingAs($this->jefe)->test('staffing::components.closing-justifications', ['loadId' => $this->world->load->id, 'campusId' => $c1->id])->viewData('total'))->toBe(1);

        $page->call('goTo', 'assign')->assertRedirect(route('staffing.assign', ['depto' => 'SC', 'modalidad' => 'PRES']));
    });

    it('un departamento sin grupos en la modalidad dice que su carga se queda en borrador', function () {
        closingPage($this->jefe)
            ->assertSee('Sistemas y Computación no tiene grupos en Presencial en Ene-Jun 2027: su carga se queda en borrador.')
            ->assertSeeHtml('data-testid="closing-empty"');
    });
});

describe('cerrar', function () {
    it('cierra con la confirmación de los grupos activos y un comentario; queda «Cerrada» y los grupos confirmados', function () {
        $this->world->group();
        $this->world->group();
        $this->world->group(['status' => GroupStatus::Cancelled->value]);

        $page = closingPage($this->jefe)
            ->call('askClose')
            ->assertSet('showClose', true)
            ->assertSee('Al cerrar, los 2 grupos activos quedan confirmados y la carga ya no se edita: horarios, docentes, salones y cupos quedan fijos. Solo Subdirección puede reabrirla.')
            ->set('closeComment', 'Todo asignado y sin choques')
            ->call('close')
            ->assertSet('showClose', false)
            ->assertSee('Cerrada el');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Closed)
            ->and(LoadTransition::query()->sole()->comment)->toBe('Todo asignado y sin choques')
            ->and($this->world->load->fresh()->closed_by)->toBe($this->jefe->id)
            ->and(lastToastOf($page))->toMatchArray(['title' => 'Carga cerrada', 'description' => 'SC en Presencial · 2 grupos confirmados.']);
    });

    it('dice los grupos que pasan con justificación', function () {
        $this->world->group();
        $this->world->justify($this->world->group(teacher: false), 'Contratación en trámite', $this->jefe);
        $this->world->justify($this->world->group(withRoom: false), 'El laboratorio se entrega en enero', $this->jefe);

        closingPage($this->jefe)->call('askClose')->assertSee('Pasan con justificación 2 grupos: 1 sin docente y 1 sin salón.');
    });

    it('si las guardas lo vetan al cerrar, muestra sus motivos sin ids de regla y la carga sigue en asignación', function () {
        $this->world->group();
        $page = closingPage($this->jefe);
        ChangeRequest::factory()->forGroup($this->world->group())->pending()->create();
        Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->to === LoadStatus::Closed ? $event->block('HC-15: 2 grupos no tienen docente ni justificación.') : null);

        $page->call('close')
            ->assertSeeHtml('data-testid="veto-reasons"')
            ->assertSee('No se cerró: la carga sigue en Asignación.')
            ->assertSee('2 grupos no tienen docente ni justificación.')
            ->assertSee('1 solicitud de cambio pendiente: apruébala o recházala en Solicitudes de cambio antes de cerrar.')
            ->assertDontSee('HC-15');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Staffing)
            ->and(lastToastOf($page)['title'])->toBe('No se cerró');
    });

    it('Subdirección cierra la carga de cualquier departamento', function () {
        $this->world->group();
        $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

        closingPage($subdireccion, ['departamento' => $this->world->department->id])->call('close');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Closed);
    });
});

describe('reabrir y volver a asignación', function () {
    beforeEach(function () {
        $this->world->load->update(['status' => LoadStatus::Closed]);
        $this->world->group();
        $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    });

    it('Subdirección reabre con motivo; sin motivo no la reabre', function () {
        $page = closingPage($this->subdireccion, ['departamento' => $this->world->department->id])
            ->call('askReopen')
            ->assertSee('La carga regresa a Ajustes y la jefatura del departamento vuelve a editarla.')
            ->call('reopen')
            ->assertHasErrors(['reopenReason' => 'Escribe el motivo para reabrir la carga.']);

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Closed);

        $page->set('reopenReason', 'Falta el grupo vespertino de Cálculo')->call('reopen')->assertHasNoErrors();

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Adjusting)
            ->and(LoadTransition::query()->sole()->comment)->toBe('Falta el grupo vespertino de Cálculo')
            ->and(lastToastOf($page)['title'])->toBe('Carga reabierta');
    });

    it('el jefe vuelve a asignación y la carga se puede cerrar otra vez', function () {
        $this->world->load->update(['status' => LoadStatus::Adjusting]);

        closingPage($this->jefe)
            ->call('returnToStaffing')
            ->assertSee('Lista para cerrar');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Staffing);
    });

    it('«Volver a asignación» se veta con solicitudes aprobadas sin aplicar y lo dice', function () {
        $this->world->load->update(['status' => LoadStatus::Adjusting]);
        $page = closingPage($this->jefe);
        ChangeRequest::factory()->forGroup($this->world->group())->approved()->create();

        $page->call('returnToStaffing')
            ->assertSee('No volvió a asignación: la carga sigue en Ajustes.')
            ->assertSee('1 solicitud de cambio aprobada sin aplicar: aplícalas antes de volver a asignación.');

        expect($this->world->load->fresh()->status)->toBe(LoadStatus::Adjusting);
    });

    it('Subdirección no vuelve a asignación: el botón dice quién lo hace', function () {
        $this->world->load->update(['status' => LoadStatus::Adjusting]);

        $page = closingPage($this->subdireccion, ['departamento' => $this->world->department->id])
            ->assertSee('Vuelve a asignación la jefatura del departamento.');

        expect(closingActionDisabled($page, 'return'))->toBeTrue();

        $page->call('returnToStaffing')->assertForbidden();
    });
});

it('«Ir a …» deja la credencial en el periodo de la carga y lleva a la pantalla donde se arregla, en el campus del filtro (?campus=, #415)', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->world->group(teacher: false, load: $this->world->loadIn($c1));
    $this->world->group();

    // La credencial está en el periodo actual por omisión (sin elección en la sesión); «Ir a …» fija el de la carga.
    expect(session(Actor::ACTIVE_TERM))->toBeNull();

    closingPage($this->jefe, ['campus' => 'C1'])
        ->call('goTo', 'assign')
        ->assertRedirect(route('staffing.assign', ['depto' => 'SC', 'modalidad' => 'PRES', 'campus' => 'C1']));

    expect(session(Actor::ACTIVE_TERM))->toBe($this->world->term->id);
});

it('«Ir a …» con un campus en el filtro donde no trabaja no agrega ?campus=', function () {
    // El admin pide un campus que se desactivó: ya no trabaja en él (Actor::campuses()).
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->world->group(teacher: false, load: $this->world->loadIn($c1));
    $this->world->group();
    $c1->update(['is_active' => false]);

    closingPage(User::factory()->admin()->create(), ['departamento' => $this->world->department->id, 'campus' => 'C1'])
        ->call('goTo', 'assign')
        ->assertRedirect(route('staffing.assign', ['depto' => 'SC', 'modalidad' => 'PRES']));
});

it('«Ir a …» abre choques, horarios, acuerdos, asignación y solicitudes en la modalidad de la carga, con el campus del filtro', function (string $screen, string $route, array $parameters) {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->world->group(teacher: false, load: $this->world->loadIn($c1));
    $this->world->group();

    closingPage($this->jefe, ['campus' => 'C1'])->call('goTo', $screen)
        ->assertRedirect(route($route, [...$parameters, 'modalidad' => 'PRES', 'campus' => 'C1']));
    closingPage($this->jefe)->call('goTo', $screen)
        ->assertRedirect(route($route, [...$parameters, 'modalidad' => 'PRES']));
})->with([
    'choques' => ['conflicts', 'scheduling.conflicts', ['vista' => 'departamentos']],
    'horarios' => ['editor', 'scheduling.editor', []],
    'acuerdos' => ['agreements', 'scheduling.agreements', []],
    'asignación' => ['assign', 'staffing.assign', ['depto' => 'SC']],
    'solicitudes' => ['change-requests', 'staffing.change-requests', ['depto' => 'SC']],
]);

it('MS6: «Ir a …» copia el campus del filtro si es de la modalidad de la carga, aunque el nombramiento sea de otro campus', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->world->group(teacher: false, load: $this->world->loadIn($c1));
    vacate(Role::JefeDepartamento, $this->world->department->id);
    $limited = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->world->campus->id, 'department_id' => $this->world->department->id, 'valid_from' => today()->toDateString()])->create();

    closingPage($limited, ['campus' => 'C1'])->call('goTo', 'editor')
        ->assertRedirect(route('scheduling.editor', ['modalidad' => 'PRES', 'campus' => 'C1']));
});

it('el aviso del cierre abre la pantalla con el departamento', function () {
    $this->world->group();

    $this->actingAs($this->jefe)->get(route('staffing.closing', ['departamento' => $this->world->department->id]))
        ->assertOk()
        ->assertSee('Sistemas y Computación');
});

/**
 * Si el botón de la acción sale deshabilitado.
 */
function closingActionDisabled(Testable $page, string $key): bool
{
    $button = domOf($page)->querySelector('[data-testid="action-'.$key.'"]');

    return $button !== null && $button->hasAttribute('disabled');
}
