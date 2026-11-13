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
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Queries\LoadClosingQuery;
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
        $this->world->campus->catalogModality->update(['settings' => [...ModalityProfile::DEFAULTS, 'requires_subdireccion_approval' => true]]);
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

describe('pestañas por modalidad y filtro de campus (M1)', function () {
    it('una pestaña por modalidad con grupos, con el estado de cada carga; sin grupos no hay pestaña', function () {
        modalityOf('EAD')->update(['is_active' => true]);
        $ead = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I', 'modality_id' => modalityOf('EAD')->id]);
        $published = $this->world->loadIn($ead, LoadStatus::Published);
        $published->update(['current_version' => 1]);
        $this->world->group();
        $this->world->group(load: $published);

        $page = closingPage($this->jefe, ['modalidad' => 'PRES'])
            ->assertSeeHtml('data-testid="modality-tab-EAD"')
            ->assertSeeHtml('data-testid="modality-tab-PRES"')
            ->assertSee('Publicada · v1')
            ->assertSee('Lista para cerrar');

        $page->call('selectModality', 'EAD')
            ->assertSet('modalidad', 'EAD')
            ->assertSee('La carga ya pasó el cierre.');
    });

    it('M1: la carga presencial junta los grupos de C1 y C2; el campus solo filtra las justificaciones', function () {
        $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
        $this->world->group(teacher: false, load: $this->world->loadIn($c1));
        $this->world->group(teacher: false);

        $page = closingPage($this->jefe)
            ->assertDontSeeHtml('data-testid="modality-tabs"')
            ->assertSee('Faltan 2 grupos para cerrar');

        expect(app(LoadClosingQuery::class)->items($this->world->load, campusId: $c1->id))->toHaveCount(1)
            ->and(app(LoadClosingQuery::class)->items($this->world->load))->toHaveCount(2);

        $page->set('campus', 'C1')->assertSet('campus', 'C1');

        expect(session('core.last_campus'))->toBe('C1');
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
        ->assertRedirect(route('staffing.assign', ['depto' => 'SC', 'campus' => 'C1']));

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
        ->assertRedirect(route('staffing.assign', ['depto' => 'SC']));
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
