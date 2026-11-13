<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Staffing\ClosingWorld;

/*
| «Avance del cierre» (tarea 4.2; maqueta E, elegida por el usuario el 10 de noviembre de 2026): Subdirección sigue el
| cierre de todo el Tec, un renglón por departamento con una insignia por carga, y desde el drawer de una carga la
| cierra o la reabre sin ser paso obligatorio (confirmado el 10 de noviembre de 2026).
*/

beforeEach(function () {
    $this->world = ClosingWorld::create();
    // Campus I es de otra modalidad para tener dos cargas del mismo departamento (M1: una por departamento y modalidad).
    modalityOf('EAD')->update(['is_active' => true]);
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I', 'modality_id' => modalityOf('EAD')->id]);
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create(['name' => 'Héctor Salinas']);
    $this->world->group();
    $this->world->group(teacher: false);
    $c1Load = $this->world->loadIn($this->c1, LoadStatus::Published);
    $c1Load->update(['current_version' => 2]);
    $this->world->group(load: $c1Load);
    $this->ee = Department::factory()->create(['code' => 'EE', 'name' => 'Eléctrica y Electrónica']);
    $this->world->group(load: $this->world->loadIn($this->world->campus, department: $this->ee));
    $this->world->loadIn($this->world->campus, LoadStatus::Draft, Department::factory()->create(['code' => 'LO', 'name' => 'Logística']));
});

function closingBoardPage(User $user): Testable
{
    return Livewire::actingAs($user)->test('staffing::pages.closing-board');
}

it('muestra las cifras de todo el Tec, un renglón por departamento con una insignia por carga y el departamento sin grupos al final', function () {
    Cache::forever(SyncScheduleConflicts::stampKey($this->world->term->id, $this->world->campus->id), now()->setTime(9, 15)->toIso8601String());
    Cache::forever(SyncScheduleConflicts::stampKey($this->world->term->id, $this->c1->id), now()->setTime(9, 40)->toIso8601String());

    $page = closingBoardPage($this->subdireccion)
        ->assertSee('Avance del cierre')
        ->assertSee('Choques recalculados hoy a las 9:15')
        ->assertSeeHtml('aria-label="Presencial: Asignación, falta 1 grupo"')
        ->assertSeeHtml('aria-label="EAD: publicada"')
        ->assertSeeHtml('aria-label="Presencial: lista para cerrar"')
        ->assertSee('Sin grupos en ninguna modalidad');

    $html = $page->html();
    expect(textOf(domOf($page)->querySelector('[data-testid="board-meta"]')))->toContain('3 departamentos · 3 cargas en C1 y C2')
        ->and(strpos($html, 'data-testid="board-row-LO"'))->toBeGreaterThan(strpos($html, 'data-testid="board-row-SC"'))
        ->and(textOf(domOf($page)->querySelector('[data-testid="kpi-missing"]')))->toContain('1 de 3')
        ->and(textOf(domOf($page)->querySelector('[data-testid="kpi-ready"]')))->toContain('1 de 3')
        ->and(textOf(domOf($page)->querySelector('[data-testid="kpi-published"]')))->toContain('1 de 3');
});

it('filtra por departamento y por estado de alguna de sus cargas', function () {
    closingBoardPage($this->subdireccion)
        ->set('search', 'electrica')
        ->assertSeeHtml('data-testid="board-row-EE"')
        ->assertDontSeeHtml('data-testid="board-row-SC"')
        ->set('search', '')
        ->set('state', 'publicadas')
        ->assertSeeHtml('data-testid="board-row-SC"')
        ->assertDontSeeHtml('data-testid="board-row-EE"');
});

it('las cifras de arriba filtran la matriz y el filtro va en la URL; tocar la elegida lo quita', function () {
    $page = Livewire::withQueryParams(['estado' => 'listas'])->actingAs($this->subdireccion)->test('staffing::pages.closing-board')
        ->assertSet('state', 'listas')
        ->assertSeeHtml('data-testid="board-row-EE"')
        ->assertDontSeeHtml('data-testid="board-row-SC"');

    expect(domOf($page)->querySelector('[data-testid="kpi-ready"]')->getAttribute('aria-pressed'))->toBe('true');

    $page->call('filterBy', 'faltantes')
        ->assertSet('state', 'faltantes')
        ->assertSeeHtml('data-testid="board-row-SC"')
        ->assertDontSeeHtml('data-testid="board-row-EE"')
        ->call('filterBy', 'faltantes')
        ->assertSet('state', '')
        ->assertSeeHtml('data-testid="board-row-EE"');
});

it('cada renglón dice quién es el jefe del departamento, o que no tiene', function () {
    $this->world->head();

    $page = closingBoardPage($this->subdireccion);

    expect(textOf(domOf($page)->querySelector('[data-testid="board-row-SC"] th')))->toContain('Laura Ramos')
        ->and(textOf(domOf($page)->querySelector('[data-testid="board-row-EE"] th')))->toContain('Sin jefe vigente');
});

it('abre el detalle de una carga: el selector de campus, lo que falta, las justificaciones, el historial y «Abrir en Cierre de la carga»', function () {
    closingBoardPage($this->subdireccion)
        ->call('open', $this->world->department->id, 'PRES')
        ->assertSet('showDrawer', true)
        ->assertSeeHtml('data-testid="drawer-modality-EAD"')
        ->assertSeeHtml('data-testid="drawer-modality-PRES"')
        ->assertSee('Falta 1 grupo para cerrar')
        ->assertSeeHtml('data-testid="condition-row-teacher"')
        ->assertSee('Para cerrar')
        ->assertSee('Justificaciones')
        ->assertSee('Historial')
        ->assertSeeHtml('href="'.e(route('staffing.closing', ['departamento' => $this->world->department->id, 'modalidad' => 'PRES'])).'"')
        ->call('selectModality', 'EAD')
        ->assertSee('Publicada · v2')
        ->assertSee('Reabrir carga');
});

it('Subdirección cierra desde el detalle, con la confirmación de los grupos activos', function () {
    $this->world->justify(Group::query()->whereNull('teacher_id')->sole(), 'Contratación en trámite', $this->subdireccion);

    closingBoardPage($this->subdireccion)
        ->call('open', $this->world->department->id, 'PRES')
        ->assertSee('Lista para cerrar')
        ->call('askClose')
        ->assertSee('Al cerrar, los 2 grupos activos quedan confirmados')
        ->call('close')
        ->assertSee('Cerrada el');

    expect($this->world->load->fresh()->status)->toBe(LoadStatus::Closed)
        ->and($this->world->load->fresh()->closed_by)->toBe($this->subdireccion->id);
});

it('Subdirección reabre una carga publicada con motivo desde el detalle', function () {
    $c1Load = $this->world->load->newQuery()->where('modality_id', $this->c1->modality_id)->sole();

    $page = closingBoardPage($this->subdireccion)
        ->call('open', $this->world->department->id, 'EAD')
        ->call('askReopen')
        ->assertSee('Mientras tanto se sigue viendo la versión publicada (v2).')
        ->set('reopenReason', 'Cambió el docente de un grupo')
        ->call('reopen')
        ->assertHasNoErrors();

    expect($c1Load->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and(lastToastOf($page)['title'])->toBe('Carga reabierta');
});

it('tocar el renglón abre la primera carga que sigue sin cerrar; si todas pasaron el cierre, la primera', function () {
    closingBoardPage($this->subdireccion)
        ->call('open', $this->world->department->id)
        ->assertSet('loadId', $this->world->load->id);

    $this->world->load->update(['status' => LoadStatus::Closed]);
    $c1Load = $this->world->load->newQuery()->where('modality_id', $this->c1->modality_id)->sole();

    closingBoardPage($this->subdireccion)
        ->call('open', $this->world->department->id)
        ->assertSet('loadId', $c1Load->id);
});

it('cerrar el drawer con Esc o con el velo olvida la carga y los modales', function () {
    closingBoardPage($this->subdireccion)
        ->call('open', $this->world->department->id, 'PRES')
        ->call('askReopen')
        ->set('showDrawer', false)
        ->assertSet('loadId', null)
        ->assertSet('showReopen', false)
        ->assertSet('blockers', null)
        ->assertDontSeeHtml('data-testid="load-drawer"');
});

it('el detalle monta la publicación de la carga debajo de la línea de estados y se refresca al publicar', function () {
    $this->world->load->update(['status' => LoadStatus::Closed]);

    $page = closingBoardPage($this->subdireccion)
        ->call('open', $this->world->department->id, 'PRES')
        ->assertSeeLivewire('academic-load::components.publish-load')
        ->assertDontSeeHtml('data-testid="action-publish"');

    expect(domOf($page)->querySelector('[data-testid="load-drawer"] [data-testid="load-publication"] [data-testid="publish-load"]'))->not->toBeNull();

    $this->world->load->update(['status' => LoadStatus::Published, 'current_version' => 1]);
    $page->dispatch('load-published', loadId: $this->world->load->id, version: 1)
        ->assertSet('blockers', null)
        ->assertSee('Publicada · v1');
});

it('el admin también ve el tablero', function () {
    closingBoardPage(User::factory()->admin()->create())->assertOk()->assertSee('Avance del cierre');
});
