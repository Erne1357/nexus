<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Actions\ReleaseStaleApplyingRun;
use App\Modules\Solver\Engines\CpSat\CpSatClient;
use App\Modules\Solver\Engines\CpSat\CpSatEngine;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
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

it('SV-06: una violación HC-08 no retiene: el modal de aceptar y el aviso de la aplicada muestran «Problemas de entrada por corregir»', function () {
    $page = launchFromRunsPage($this->jefe);
    $run = SolverRun::query()->sole();
    $run->update(['output' => [...$run->output, 'hard_violations' => [['rule' => 'HC-08', 'group_ids' => [$this->second->id], 'message' => 'Ana Pérez no tiene horas libres.']]]]);

    $page->call('openAccept')
        ->assertSeeHtml('data-testid="accept-input-problems"')
        ->assertSee('Problemas de entrada por corregir')
        ->assertSee('Ana Pérez no tiene horas libres.')
        ->call('apply');

    expect($this->second->sessions()->count())->toBeGreaterThan(0);

    $page->assertSeeHtml('data-testid="applied-input-problems"')->assertSee('Problemas de entrada por corregir');
});

it('SV-02: con la carga cambiada, «Aceptar» abre el aviso de entrada vencida y no escribe', function () {
    $page = launchFromRunsPage($this->jefe);
    GroupSession::factory()->for($this->second)->inRoom($this->room)->on(5, '13:00', '14:00')->create();

    $page->call('openAccept')->assertSet('showAccept', false)->assertSet('showStale', true)->assertSee('La carga cambió desde la corrida 1');

    expect(SolverRun::query()->sole()->status)->toBe(SolverRunStatus::Done);
});

it('E-10: con la carga publicada, «Aceptar» sale deshabilitado con el motivo real y un aviso dice que las corridas solo exploran', function () {
    $page = launchFromRunsPage($this->jefe);
    AcademicLoad::query()->sole()->forceFill(['status' => LoadStatus::Published])->save();

    $page->call('$refresh')->assertSeeHtml('data-testid="run-accept-disabled"')
        ->assertDontSeeHtml('data-testid="run-accept"')
        ->assertSeeHtml('data-testid="run-published-note"')
        ->assertSee('La carga está publicada: para cambiar su horario, inicia el re-análisis')
        ->assertSee('las corridas sirven para explorar; no se pueden aceptar hasta que se inicie el re-análisis')
        ->assertSeeHtml('data-testid="launch-open"')
        ->call('openAccept')
        ->assertSet('showAccept', false)
        ->assertSet('showStale', false);

    expect(SolverRun::query()->sole()->status)->toBe(SolverRunStatus::Done);
});

it('E-10: aceptar por una petición vieja con la carga publicada da el motivo de editabilidad, no «La carga cambió»', function () {
    $page = launchFromRunsPage($this->jefe);
    AcademicLoad::query()->sole()->forceFill(['status' => LoadStatus::Published])->save();
    GroupSession::factory()->for($this->second)->inRoom($this->room)->on(5, '13:00', '14:00')->create();

    $page->call('apply')->assertSet('showStale', false)->assertSee('La carga está publicada: para cambiar su horario, inicia el re-análisis');

    expect(SolverRun::query()->sole()->status)->toBe(SolverRunStatus::Done);
});

it('E-10: con la carga en re-análisis «Aceptar» sigue habilitado y no sale el aviso de publicada', function () {
    $page = launchFromRunsPage($this->jefe);
    AcademicLoad::query()->sole()->forceFill(['status' => LoadStatus::Reanalysis])->save();

    $page->call('$refresh')->assertSeeHtml('data-testid="run-accept"')
        ->assertDontSeeHtml('data-testid="run-accept-disabled"')
        ->assertDontSeeHtml('data-testid="run-published-note"');
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

it('SV-03: cancela la corrida en cola; el avance solo se lee mientras está en curso', function () {
    Queue::fake();
    $page = launchFromRunsPage($this->jefe)->assertSeeLivewire('solver::components.run-progress');
    $run = SolverRun::query()->sole();

    expect($run->status)->toBe(SolverRunStatus::Queued);

    $page->call('cancel')->assertDontSeeLivewire('solver::components.run-progress')->assertDontSeeHtml('wire:poll');

    expect($run->fresh()->status)->toBe(SolverRunStatus::Cancelled);
});

it('el poll ligero lee solo el avance de la corrida que corre y avisa a la página al terminar', function () {
    $run = SolverRun::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'status' => SolverRunStatus::Running,
        'started_at' => now()->subSeconds(30), 'progress' => 40, 'best_score' => 120, 'input' => ['version' => 1, 'time_limit_seconds' => 300]]);

    $progress = Livewire::actingAs($this->jefe)->test('solver::components.run-progress', ['runId' => $run->id])
        ->assertSeeHtml('wire:poll.2s')
        ->assertSee('40')
        ->assertSee('120')
        ->assertSee('05:00')
        ->assertNotDispatched('run-finished');

    $run->update(['status' => SolverRunStatus::Done, 'progress' => 100]);

    $progress->call('$refresh')->assertDispatched('run-finished')->assertDontSeeHtml('wire:poll');

    Livewire::actingAs(User::factory()->withRole(Role::Docente)->create())->test('solver::components.run-progress', ['runId' => $run->id])->assertForbidden();
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

it('una corrida de un motor que ya no está registrado muestra su nombre y «Volver a correr» propone el predeterminado', function () {
    $run = SolverRun::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'engine' => 'cpsat', 'status' => SolverRunStatus::Failed, 'error' => 'El servicio del motor no está disponible.', 'input' => ['version' => 1, 'time_limit_seconds' => 120]]);

    runsPage($this->jefe, ['corrida' => $run->id])
        ->assertSee('CP-SAT (servicio)')
        ->assertDontSee('cpsat ·')
        ->call('openLaunch', $run->id)
        ->assertSet('launchEngine', 'heuristic')
        ->assertSet('launchLimit', 120)
        ->assertSeeHtml('data-testid="launch-engine-unavailable"')
        ->assertSee('CP-SAT (servicio) no está disponible ahora');
});

it('7.11: «Lanzar una corrida» propone el motor predeterminado de la modalidad: CP-SAT en Presencial con el servicio, la heurística con una nota sin él', function () {
    runsPage($this->jefe)->call('openLaunch')
        ->assertSet('launchEngine', 'heuristic')
        ->assertSeeHtml('data-testid="launch-engine-unconfigured"')
        ->assertSee('CP-SAT (servicio) no está disponible en este servidor; se propone Heurística (PHP).');

    app()->instance(EngineRegistry::class, new EngineRegistry([new CpSatEngine(new CpSatClient('http://solver.test', 'secreto')), new FixedEngine, new PhpHeuristicEngine]));

    runsPage($this->jefe)->call('openLaunch')
        ->assertSet('launchEngine', 'cpsat')
        ->assertDontSeeHtml('data-testid="launch-engine-unconfigured"');

    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'solver.default_engine', 'value' => ['pres' => 'heuristic', 'ead' => 'heuristic', 'enl' => 'heuristic', 'tnmv' => 'heuristic']]);

    runsPage($this->jefe)->call('openLaunch')
        ->assertSet('launchEngine', 'heuristic')
        ->assertDontSeeHtml('data-testid="launch-engine-unconfigured"');
});

it('el componente de avance también cubre «Aplicando…» y avisa a la página cuando la corrida termina de aplicarse', function () {
    $run = SolverRun::factory()->done()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'status' => SolverRunStatus::Applying, 'input' => ['version' => 1, 'time_limit_seconds' => 60]]);

    $progress = Livewire::actingAs($this->jefe)->test('solver::components.run-progress', ['runId' => $run->id])
        ->assertSeeHtml('wire:poll.2s')
        ->assertSee('Aplicando')
        ->assertNotDispatched('run-finished');

    $run->update(['status' => SolverRunStatus::Applied]);

    $progress->call('$refresh')->assertDispatched('run-finished')->assertDontSeeHtml('wire:poll');
});

it('al abrir, una corrida que se quedó aplicándose más del límite vuelve a estar lista con su motivo; una reciente no', function () {
    $stale = SolverRun::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'status' => SolverRunStatus::Applying, 'output' => null]);
    SolverRun::query()->whereKey($stale->id)->update(['updated_at' => now()->subSeconds(RunSolver::STALE_AFTER + 60)]);

    runsPage($this->jefe, ['corrida' => $stale->id]);

    expect($stale->fresh())
        ->status->toBe(SolverRunStatus::Done)
        ->metrics->toBe(['apply_error' => ReleaseStaleApplyingRun::MESSAGE]);

    $recent = SolverRun::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->sistemas->id, 'status' => SolverRunStatus::Applying, 'output' => null]);

    runsPage($this->jefe);

    expect($recent->fresh()->status)->toBe(SolverRunStatus::Applying);
});

it('E-10: con la carga cerrada o aprobada, «Aceptar» sale deshabilitado con su motivo y sin el aviso de publicada', function (LoadStatus $status, string $reason) {
    $page = launchFromRunsPage($this->jefe);
    AcademicLoad::query()->sole()->forceFill(['status' => $status])->save();

    $page->call('$refresh')
        ->assertSeeHtml('data-testid="run-accept-disabled"')
        ->assertDontSeeHtml('data-testid="run-accept"')
        ->assertSee($reason)
        ->assertDontSeeHtml('data-testid="run-published-note"');
})->with([
    'cerrada' => [LoadStatus::Closed, 'La carga ya no se puede editar: está cerrada.'],
    'aprobada' => [LoadStatus::Approved, 'La carga ya no se puede editar: está aprobada.'],
]);
