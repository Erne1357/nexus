<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\ScheduleConflictsSynced;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Actions\ApplySolverRun;
use App\Modules\Solver\Database\Seeders\SolverRunsDemoSeeder;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Queries\SolverRunsQuery;
use App\Modules\Solver\SolverServiceProvider;
use App\Modules\Solver\Support\RunPayloads;
use Database\Seeders\DemoSeeder;
use Database\Seeders\MassiveSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
| Corridas del motor en la demo (SolverRunsDemoSeeder, pantalla «Corridas del motor» de la tarea 6.10): en tres
| departamentos, corridas en todos sus estados. Las que se pueden lanzar se lanzan de verdad como el jefe o el
| coordinador; las que no se dejan solas salen de la factory con la entrada real de su alcance. Idempotente.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18']);
    $this->pres = modalityOf('PRES');
    $this->north = Campus::factory()->create(['code' => 'K1']);
    $this->south = Campus::factory()->create(['code' => 'K2']);

    $this->sistemas = solverDemoDepartment('SC', LoadStatus::Staffing);
    $this->industrial = solverDemoDepartment('II', LoadStatus::Proposed);
    $this->basicas = solverDemoDepartment('CB', LoadStatus::Staffing);

    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->sistemas->id]);
    $this->iind = Career::factory()->create(['code' => 'IIND', 'department_id' => $this->industrial->id]);

    solverDemoGroup($this->sistemas, $this->isc, $this->south, 1);
    solverDemoGroup($this->sistemas, $this->isc, $this->south, 2);
    solverDemoGroup($this->industrial, $this->isc, $this->south, 4);
    solverDemoGroup($this->basicas, $this->isc, $this->north, 3);
    solverDemoGroup($this->basicas, $this->iind, $this->south, 3);

    $this->coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->south->id, 'career_id' => $this->isc->id])->create();
});

function solverDemoDepartment(string $code, LoadStatus $status): Department
{
    $department = Department::factory()->create(['code' => $code]);
    AcademicLoad::factory()->inStatus($status)->create(['term_id' => test()->term->id, 'modality_id' => test()->pres->id, 'department_id' => $department->id]);
    User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create(['username' => 'jefe.'.strtolower($code)]);

    return $department;
}

function solverDemoGroup(Department $department, Career $career, Campus $campus, int $semester): Group
{
    $room = Room::query()->whereRelation('building', 'campus_id', $campus->id)->first()
        ?? Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $campus->id])->id, 'capacity' => 40]);
    $group = Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => $campus->id,
        'modality_id' => test()->pres->id,
        'department_id' => $department->id,
        'subject_id' => Subject::factory()->create(['department_id' => $department->id, 'theory_hours' => 2, 'practice_hours' => 0])->id,
        'owner_career_id' => $career->id,
        'study_plan_id' => StudyPlan::factory()->create(['career_id' => $career->id])->id,
        'semester' => $semester,
        'teacher_id' => Teacher::factory()->create()->id,
    ]);
    $start = 7 + 2 * GroupSession::query()->count();
    GroupSession::factory()->for($group)->inRoom($room)->on($semester, sprintf('%02d:00', $start), sprintf('%02d:00', $start + 2))->create();

    return $group;
}

/**
 * Los estados de las corridas de un alcance, contados.
 *
 * @return array<string, int>
 */
function solverDemoStatuses(RunScope $scope, string $scopeId): array
{
    return SolverRun::query()
        ->where('scope_kind', $scope)
        ->where($scope === RunScope::Department ? 'department_id' : 'career_id', $scopeId)
        ->pluck('status')
        ->map(fn (SolverRunStatus $status): string => $status->value)
        ->countBy()
        ->sortKeys()
        ->all();
}

function seedSolverRunsDemo(): void
{
    app(SolverRunsDemoSeeder::class)->run('PRES', ['K1', 'K2'], '20271');
}

it('deja corridas en todos sus estados en tres departamentos y en una carrera del principal', function () {
    seedSolverRunsDemo();

    expect(solverDemoStatuses(RunScope::Department, $this->sistemas->id))->toBe(['cancelled' => 1, 'discarded' => 1, 'done' => 1, 'failed' => 1, 'queued' => 1])
        ->and(solverDemoStatuses(RunScope::Career, $this->isc->id))->toBe(['done' => 1, 'running' => 1])
        ->and(solverDemoStatuses(RunScope::Department, $this->industrial->id))->toBe(['applied' => 1])
        ->and(solverDemoStatuses(RunScope::Department, $this->basicas->id))->toBe(['done' => 2])
        ->and(SolverRun::query()->distinct()->pluck('status')->map->value->sort()->values()->all())
        ->toBe(['applied', 'cancelled', 'discarded', 'done', 'failed', 'queued', 'running']);
});

it('lanza las corridas reales como el jefe o el coordinador, con la heurística y el motor de prueba, y el jefe del principal y el coordinador abren una lista, el coordinador con todos los estados en su historial', function () {
    seedSolverRunsDemo();

    $jefe = User::query()->where('username', 'jefe.sc')->sole();
    $history = array_column(app(SolverRunsQuery::class)->forDepartment($this->term, $this->pres, $this->sistemas, $jefe), 'run');
    $newest = $history[0];

    expect($newest->status)->toBe(SolverRunStatus::Done)
        ->and($newest->engine)->toBe('heuristic')
        ->and($newest->created_by)->toBe($jefe->id)
        ->and($newest->metrics)->toHaveKeys(['result', 'sessions', 'unscheduled', 'hard_violations', 'soft_score'])
        ->and(array_map(fn (SolverRun $run): string => $run->status->value, $history))->toBe(['done', 'discarded', 'queued', 'cancelled', 'failed'])
        ->and(SolverRun::query()->where('department_id', $this->basicas->id)->where('status', SolverRunStatus::Done)->pluck('engine')->sort()->values()->all())->toBe(['fixed', 'heuristic'])
        ->and(SolverRun::query()->where('career_id', $this->isc->id)->where('status', SolverRunStatus::Done)->sole()->created_by)->toBe($this->coordinador->id);

    $coordinatorSees = array_column(app(SolverRunsQuery::class)->forCareer($this->term, $this->pres, $this->isc, $this->coordinador), 'run');

    expect($coordinatorSees[0]->career_id)->toBe($this->isc->id)
        ->and($coordinatorSees[0]->status)->toBe(SolverRunStatus::Done)
        ->and(collect($coordinatorSees)->map(fn (SolverRun $run): string => $run->status->value)->unique()->sort()->values()->all())
        ->toBe(['applied', 'cancelled', 'discarded', 'done', 'failed', 'queued', 'running']);

    $applied = SolverRun::query()->where('status', SolverRunStatus::Applied)->sole();

    expect($applied->engine)->toBe('heuristic')
        ->and($applied->applied_by)->toBe(User::query()->where('username', 'jefe.ii')->sole()->id)
        ->and(Activity::query()->where('subject_id', $applied->id)->where('event', 'applied')->exists())->toBeTrue();
});

it('las que no se dejan solas traen la entrada real de su alcance, su huella y lo que la pantalla lee de su estado', function () {
    seedSolverRunsDemo();

    $failed = SolverRun::query()->where('status', SolverRunStatus::Failed)->sole();
    $running = SolverRun::query()->where('status', SolverRunStatus::Running)->sole();
    $queued = SolverRun::query()->where('status', SolverRunStatus::Queued)->sole();
    $cancelled = SolverRun::query()->where('status', SolverRunStatus::Cancelled)->sole();
    $discarded = SolverRun::query()->where('status', SolverRunStatus::Discarded)->sole();
    $current = SolverRun::query()->where('department_id', $this->sistemas->id)->where('status', SolverRunStatus::Done)->sole();

    foreach ([$failed, $running, $queued, $cancelled, $discarded] as $run) {
        expect(RunPayloads::input($run)->runId)->toBe($run->id);
    }

    expect($failed->engine)->toBe('cpsat')
        ->and($failed->error)->toBe('El servicio del motor no está disponible.')
        ->and($failed->input_hash)->toBe($current->input_hash)
        ->and($running->scope_kind)->toBe(RunScope::Career)
        ->and($running->progress)->toBeGreaterThan(0)->toBeLessThan(100)
        ->and($running->started_at)->not->toBeNull()
        ->and($running->created_by)->toBe($this->coordinador->id)
        ->and($queued->department_id)->toBe($this->sistemas->id)
        ->and($queued->started_at)->toBeNull()
        ->and($queued->updated_at->lt(now()->subSeconds(RunSolver::STALE_AFTER)))->toBeTrue()
        ->and(Activity::query()->where('subject_id', $cancelled->id)->where('event', 'cancelled')->exists())->toBeTrue()
        ->and(Activity::query()->where('subject_id', $discarded->id)->where('event', 'discarded')->exists())->toBeTrue()
        ->and(RunPayloads::output($discarded))->not->toBeNull();
});

it('es idempotente: volver a sembrar deja los mismos conteos', function () {
    seedSolverRunsDemo();
    $before = SolverRun::query()->pluck('status')->map->value->countBy()->sortKeys()->all();

    seedSolverRunsDemo();

    expect(SolverRun::query()->pluck('status')->map->value->countBy()->sortKeys()->all())->toBe($before)
        ->and(SolverRun::query()->count())->toBe(10);
});

it('es idempotente por estado: si a una siembra le faltó una corrida, la siguiente solo crea esa', function () {
    seedSolverRunsDemo();
    $before = SolverRun::query()->pluck('status')->map->value->countBy()->sortKeys()->all();
    $kept = SolverRun::query()->where('status', '!=', SolverRunStatus::Queued)->pluck('id')->sort()->values()->all();
    SolverRun::query()->where('status', SolverRunStatus::Queued)->delete();

    seedSolverRunsDemo();

    expect(SolverRun::query()->pluck('status')->map->value->countBy()->sortKeys()->all())->toBe($before)
        ->and(SolverRun::query()->where('status', '!=', SolverRunStatus::Queued)->pluck('id')->sort()->values()->all())->toBe($kept);
});

it('acepta primero: las corridas listas de departamento que lanza después siguen vigentes (SV-02)', function () {
    seedSolverRunsDemo();

    $done = SolverRun::query()->where('scope_kind', RunScope::Department)->where('status', SolverRunStatus::Done)->get();

    expect($done)->toHaveCount(3)
        ->and($done->reject(fn (SolverRun $run): bool => app(ApplySolverRun::class)->isCurrent($run))->pluck('engine')->all())->toBe([]);
});

it('después de aceptar manda un solo GroupScheduleChanged, con las horas cambiadas, de un grupo de la carga aceptada', function () {
    Event::fake([GroupScheduleChanged::class]);

    seedSolverRunsDemo();

    Event::assertDispatchedTimes(GroupScheduleChanged::class, 1);
    Event::assertDispatched(GroupScheduleChanged::class, fn (GroupScheduleChanged $event): bool => $event->timesChanged && $event->group->department_id === $this->industrial->id);
});

it('si los choques quedan por recalcular después de aceptar, se detiene con el motivo', function () {
    Event::listen(ScheduleConflictsSynced::class, fn () => throw new RuntimeException('Valkey no responde.'));

    expect(fn () => seedSolverRunsDemo())->toThrow(RuntimeException::class, 'La corrida de II se aplicó, pero los choques quedaron por recalcular (conflicts_pending).');
});

it('sin aceptar, la corrida del departamento de la aceptación se queda lista', function () {
    app(SolverRunsDemoSeeder::class)->run('PRES', ['K1', 'K2'], '20271', apply: false);

    expect(solverDemoStatuses(RunScope::Department, $this->industrial->id))->toBe(['done' => 1])
        ->and(SolverRun::query()->where('status', SolverRunStatus::Applied)->exists())->toBeFalse();
});

it('sin periodo o sin grupos en los campus no hace nada', function () {
    app(SolverRunsDemoSeeder::class)->run('PRES', ['K1', 'K2'], '20991');
    app(SolverRunsDemoSeeder::class)->run('PRES', ['ZZ'], '20271');

    expect(SolverRun::query()->count())->toBe(0);
});

it('el paso de la demo por puntos lleva a «asignacion» y siembra cada modalidad del volumen sin aceptar', function () {
    $calls = new ArrayObject;
    app()->instance(SolverRunsDemoSeeder::class, new class($calls) extends Seeder
    {
        public function __construct(private ArrayObject $calls) {}

        public function run(string $modality = 'sin modalidad', array $campuses = [], string $term = '', bool $apply = true): void
        {
            $this->calls[] = compact('modality', 'campuses', 'term', 'apply');
        }
    });

    $steps = app()->getProvider(SolverServiceProvider::class)->demoSteps();

    foreach ($steps as $step) {
        ($step->run)(DemoContext::for(DemoVolume::Massive));
    }

    expect(array_map(fn ($step) => $step->reaches, $steps))->toBe([DemoCheckpoint::Staffing])
        ->and($calls->getArrayCopy())->toBe([['modality' => 'PRES', 'campuses' => ['C1', 'C2'], 'term' => '20271', 'apply' => false]]);
});

it('la revisión de la demo pasa con todos los estados y dice cuáles faltan', function () {
    $check = collect(app()->getProvider(SolverServiceProvider::class)->demoChecks())->firstWhere('label', 'Hay corridas del motor en todos sus estados');
    $demo = new DemoContext(DemoVolume::Demo, ['K1', 'K2']);

    expect($check->at)->toBe(DemoCheckpoint::since(DemoCheckpoint::Staffing))
        ->and(($check->passes)($demo))->toBe('Faltan corridas del motor en Presencial: en cola, corriendo, lista, fallida, descartada y cancelada.');

    seedSolverRunsDemo();

    expect(($check->passes)($demo))->toBeTrue();
});

it('DemoSeeder y MassiveSeeder siembran las corridas solo con demo.showcase', function () {
    $calls = new ArrayObject;
    app()->instance(SolverRunsDemoSeeder::class, new class($calls) extends Seeder
    {
        public function __construct(private ArrayObject $calls) {}

        public function run(string $modality = 'PRES', array $campuses = [], string $term = '20271', bool $apply = true): void
        {
            $this->calls[] = compact('campuses', 'apply');
        }
    });

    app(DemoSeeder::class)->setContainer(app())->solverRuns();
    app(MassiveSeeder::class)->setContainer(app())->solverRuns();
    expect($calls->getArrayCopy())->toBe([]);

    config(['demo.showcase' => true]);
    app(DemoSeeder::class)->setContainer(app())->solverRuns();
    app(MassiveSeeder::class)->setContainer(app())->solverRuns();

    expect($calls->getArrayCopy())->toBe([
        ['campuses' => ['C2'], 'apply' => true],
        ['campuses' => ['C1', 'C2'], 'apply' => true],
    ]);
});

it('la revisión de vigencia de «asignacion» pasa con lo que deja el paso y nombra el departamento cuya carga cambió', function () {
    $check = collect(app()->getProvider(SolverServiceProvider::class)->demoChecks())->firstWhere('label', 'Las corridas listas de departamento siguen vigentes (SV-02)');
    $demo = new DemoContext(DemoVolume::Demo, ['K1', 'K2']);
    app(SolverRunsDemoSeeder::class)->run('PRES', ['K1', 'K2'], '20271', apply: false);

    expect($check->at)->toBe([DemoCheckpoint::Staffing])
        ->and(($check->passes)($demo))->toBeTrue();

    GroupSession::query()->whereRelation('group', 'department_id', $this->basicas->id)->first()->update(['starts_at' => '16:00', 'ends_at' => '18:00']);

    expect(($check->passes)($demo))->toBe('Vencidas: CB (2).');
});
