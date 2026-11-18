<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| La pantalla de corridas del motor (tarea 6.10, `/solver/runs`, solver::pages.runs; variante C «Semana con cambios»).
| El jefe ve, lanza y acepta las corridas de su carga; el coordinador ve las suyas y las de departamento de las cargas que
| tocan su carrera, nunca las de otro coordinador, y no acepta; Subdirección ve todas y no lanza; el docente no entra.
| `?corrida=` abre esa corrida y la que corre se lee con `wire:poll`, solo mientras corre.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-18']);
    $this->pres = modalityOf('PRES');
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sistemas = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $this->sistemas->id]);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales', 'department_id' => $this->sistemas->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->isc->id, 'code' => 'ISIC-2010']);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'capacity' => 40, 'code' => 'LC-1']);
    $this->teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $this->first = runsPageGroup('SCD-1015', 1);
    $this->second = runsPageGroup('SCD-1016', 1);
    GroupSession::factory()->for($this->first)->inRoom($this->room)->on(3, '12:00', '13:00')->create();

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sistemas->id])->create();
    $this->coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
});

function runsPageGroup(string $subject, int $semester): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'modality_id' => test()->pres->id,
        'subject_id' => Subject::factory()->create(['code' => $subject, 'department_id' => test()->sistemas->id, 'theory_hours' => 2, 'practice_hours' => 0])->id,
        'owner_career_id' => test()->isc->id,
        'study_plan_id' => test()->plan->id,
        'semester' => $semester,
        'teacher_id' => test()->teacher->id,
    ]);
}

function runsPage(User $user, array $query = [])
{
    onCampusPage(null);

    return Livewire::withQueryParams($query)->actingAs($user)->test('solver::pages.runs');
}

/**
 * El jefe lanza la carga de Sistemas con el motor de prueba desde la página (cola síncrona: vuelve terminada).
 */
function launchFromRunsPage(User $user)
{
    return runsPage($user)
        ->call('openLaunch')
        ->assertSet('showLaunch', true)
        ->assertSet('launchScope', 'department')
        ->set('launchEngine', 'fixed')
        ->call('launch')
        ->assertHasNoErrors();
}

describe('quién entra', function () {
    it('el jefe, el coordinador y Subdirección entran; el docente no; el invitado va al login', function () {
        foreach ([$this->jefe, $this->coordinador, User::factory()->withRole(Role::Subdireccion)->create()] as $user) {
            $this->actingAs($user)->get(route('solver.runs'))->assertOk()->assertSee('Corridas del motor');
        }

        $this->actingAs(User::factory()->withRole(Role::Docente)->create())->get(route('solver.runs'))->assertForbidden();
        auth()->logout();
        $this->get(route('solver.runs'))->assertRedirect(route('login'));
    });

    it('un usuario sin permiso no abre una corrida por la URL ni llama sus métodos', function () {
        $run = SolverRun::factory()->done()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'output' => null]);
        $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => departmentKey(70)])->create();

        $this->actingAs($otherHead)->get(route('solver.runs', ['corrida' => $run->id]))->assertForbidden();

        $component = runsPage($this->jefe);
        $this->actingAs(User::factory()->withRole(Role::Docente)->create());
        $component->call('discard')->assertForbidden();
    });
});

it('el jefe lanza con el motor de prueba, ve la semana con los cambios y acepta: se escribe el horario', function () {
    $page = launchFromRunsPage($this->jefe);
    $run = SolverRun::query()->sole();

    expect($run->status)->toBe(SolverRunStatus::Done)->and($page->get('runId'))->toBe($run->id);

    $page->assertSeeHtml('data-testid="run-week"')
        ->assertSeeHtml('data-testid="run-tray"')
        ->assertSee('Con la corrida')
        ->assertSee('Cambian')
        ->assertDontSeeHtml('wire:poll')
        ->call('openAccept')
        ->assertSet('showAccept', true)
        ->assertSee('¿Aceptar la corrida 1?')
        ->call('apply')
        ->assertSet('showAccept', false);

    expect($run->fresh()->status)->toBe(SolverRunStatus::Applied)
        ->and($this->second->sessions()->count())->toBeGreaterThan(0);

    $page->assertSee('Aplicada');
});

it('SV-02: con la carga cambiada, «Aceptar» abre el aviso de entrada vencida y no escribe', function () {
    $page = launchFromRunsPage($this->jefe);
    GroupSession::factory()->for($this->second)->inRoom($this->room)->on(5, '13:00', '14:00')->create();

    $page->call('openAccept')->assertSet('showAccept', false)->assertSet('showStale', true)->assertSee('La carga cambió desde la corrida 1');

    expect(SolverRun::query()->sole()->status)->toBe(SolverRunStatus::Done);
});

it('el coordinador ve las suyas y las de departamento de su carrera, no las de otro coordinador, y no acepta', function () {
    $department = SolverRun::factory()->done()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'output' => null]);
    $own = SolverRun::factory()->forCareer()->create(['term_id' => $this->term->id, 'career_id' => $this->isc->id, 'created_by' => $this->coordinador->id, 'status' => SolverRunStatus::Failed, 'error' => 'Se acabó la memoria.']);
    $other = SolverRun::factory()->forCareer()->create(['term_id' => $this->term->id, 'career_id' => Career::factory()->create(['code' => 'IIND', 'department_id' => $this->sistemas->id])->id]);

    $page = runsPage($this->coordinador)
        ->assertSet('scopeKind', 'career')
        ->assertSee('Corridas que ves de tu carrera');

    expect(array_map(fn (array $row): string => $row['run']->id, $page->viewData('runs')))->toEqualCanonicalizing([$department->id, $own->id])
        ->and($page->viewData('can')['apply'])->toBeFalse();

    $page->call('selectRun', $other->id)->assertForbidden();
});

it('el coordinador no acepta una corrida de departamento aunque la vea', function () {
    $page = launchFromRunsPage($this->jefe);
    $run = SolverRun::query()->sole();

    runsPage($this->coordinador, ['corrida' => $run->id])
        ->assertSet('runId', $run->id)
        ->assertSeeHtml('data-testid="run-accept-disabled"')
        ->assertSee('Solo el jefe de Sistemas y Computación acepta una corrida.')
        ->call('apply')
        ->assertForbidden();

    expect($run->fresh()->status)->toBe(SolverRunStatus::Done);
});

it('Subdirección ve las corridas de todos los departamentos y no lanza', function () {
    $run = SolverRun::factory()->done()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'output' => null]);
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    runsPage($subdireccion)
        ->assertSet('runId', $run->id)
        ->assertSee('Las lanzan el jefe del departamento y los coordinadores.')
        ->set('launchEngine', 'fixed')
        ->call('launch');

    expect(SolverRun::query()->count())->toBe(1);
});

it('SV-07: descartar desde la página deja la corrida descartada y no toca ninguna sesión', function () {
    $page = launchFromRunsPage($this->jefe);
    $sessions = GroupSession::query()->count();

    $page->call('discard')->assertSee('Descartada');

    expect(SolverRun::query()->sole()->status)->toBe(SolverRunStatus::Discarded)
        ->and(GroupSession::query()->count())->toBe($sessions);
});

it('SV-03: cancela la corrida en cola y la que corre se lee con wire:poll solo mientras corre', function () {
    Queue::fake();
    $page = launchFromRunsPage($this->jefe)->assertSeeHtml('wire:poll.2s="refreshRun"');
    $run = SolverRun::query()->sole();

    expect($run->status)->toBe(SolverRunStatus::Queued);

    $page->call('cancel')->assertDontSeeHtml('wire:poll');

    expect($run->fresh()->status)->toBe(SolverRunStatus::Cancelled);
});

it('?corrida= abre esa corrida y no la más nueva', function () {
    $older = SolverRun::factory()->done()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'created_at' => now()->subHour(), 'output' => null]);
    $newer = SolverRun::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'status' => SolverRunStatus::Failed, 'error' => 'No se pudo armar la entrada.']);

    runsPage($this->jefe)->assertSet('runId', $newer->id)->assertSee('No se pudo armar la entrada.');
    runsPage($this->jefe, ['corrida' => $older->id])->assertSet('runId', $older->id);

    expect(RunScope::Department)->toBe($older->scope_kind);
});

it('con la corrida aplicándose la pantalla hace poll, deshabilita Aceptar y Lanzar con su motivo', function () {
    $run = SolverRun::factory()->done()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'output' => null, 'status' => SolverRunStatus::Applying]);

    runsPage($this->jefe, ['corrida' => $run->id])
        ->assertSeeHtml('data-testid="run-applying"')
        ->assertSeeHtml('wire:poll')
        ->assertSee('Aplicando')
        ->assertSeeHtml('data-testid="run-accept-disabled"')
        ->assertSee('Se está aplicando');
});

it('una corrida lista con error de aplicación lo muestra con «Volver a correr»', function () {
    $run = SolverRun::factory()->done()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'output' => null, 'metrics' => ['apply_error' => 'Se acabó la memoria.']]);

    runsPage($this->jefe, ['corrida' => $run->id])
        ->assertSeeHtml('data-testid="run-apply-error"')
        ->assertSee('Se acabó la memoria.')
        ->assertSee('Volver a correr');
});
