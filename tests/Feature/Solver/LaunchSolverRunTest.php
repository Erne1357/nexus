<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Notifications\SolverRunFinished;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Rules\InputFingerprint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Lanzar una corrida del motor (tarea 6.8): la corrida nace en cola con la entrada que arma BuildSolverInput, su huella
| (SV-02), su motor y su límite de tiempo (SV-04), deja bitácora y despacha RunSolver. Un departamento tiene a lo más una
| corrida en cola o corriendo (SV-01); las de carrera conviven. La Action autoriza con SolverRunPolicy (launchForDepartment
| o launchForCareer) a quien la lanza.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->pres = modalityOf('PRES');
    $this->campus = Campus::factory()->create(['code' => 'L1']);
    $this->sistemas = Department::factory()->create(['name' => 'Sistemas']);
    $this->isc = Career::factory()->create(['department_id' => $this->sistemas->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->isc->id]);
    $this->subject = Subject::factory()->create(['department_id' => $this->sistemas->id, 'code' => 'SCC-1010', 'theory_hours' => 3, 'practice_hours' => 1]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'capacity' => 40]);

    $this->placed = launchRunGroup(1);
    $this->pending = launchRunGroup(2);
    $this->session = GroupSession::factory()->for($this->placed)->inRoom($this->room)->on(1, '07:00', '09:00')->create();

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sistemas->id])->create();
    $this->coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
});

function launchRunGroup(int $semester): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'modality_id' => test()->pres->id,
        'subject_id' => test()->subject->id,
        'owner_career_id' => test()->isc->id,
        'study_plan_id' => test()->plan->id,
        'semester' => $semester,
        'capacity' => 30,
    ]);
}

function launchSolverRun(RunScope $scope = RunScope::Department, ?string $scopeId = null, string $engine = 'fixed', int $seconds = 120, ?User $by = null): SolverRun
{
    $department = $scope === RunScope::Department;
    $scopeId ??= $department ? test()->sistemas->id : test()->isc->id;

    return app(LaunchSolverRun::class)->handle(test()->term, test()->pres, $scope, $scopeId, $engine, $seconds, $by ?? ($department ? test()->jefe : test()->coordinador));
}

/**
 * La huella de un armado nuevo de la carga de Sistemas, con un identificador de corrida cualquiera.
 */
function launchLoadFingerprint(): string
{
    return InputFingerprint::of(app(BuildSolverInput::class)->forDepartment(test()->term, test()->pres, test()->sistemas, (string) Str::uuid7())->input);
}

it('nace en cola con su entrada, su huella, su motor y su límite, deja bitácora y despacha el job', function () {
    Queue::fake();

    $run = launchSolverRun();
    $movable = array_column(array_filter($run->input['groups'], fn (array $group): bool => $group['movable']), 'id');

    expect($run)
        ->status->toBe(SolverRunStatus::Queued)
        ->scope_kind->toBe(RunScope::Department)
        ->department_id->toBe($this->sistemas->id)
        ->career_id->toBeNull()
        ->term_id->toBe($this->term->id)
        ->modality_id->toBe($this->pres->id)
        ->engine->toBe('fixed')
        ->created_by->toBe($this->jefe->id)
        ->progress->toBe(0)
        ->input_path->toBeNull()
        ->and($run->input['run_id'])->toBe($run->id)
        ->and($run->input['time_limit_seconds'])->toBe(120)
        ->and($movable)->toEqualCanonicalizing([$this->placed->id, $this->pending->id])
        ->and($run->input_hash)->toBe(InputFingerprint::of(SolverInput::fromArray($run->input)))
        ->and($run->input_hash)->toBe(launchLoadFingerprint())
        ->and(Activity::query()->where('subject_id', $run->id)->sole())
        ->event->toBe('launched')
        ->causer_id->toBe($this->jefe->id);

    Queue::assertPushed(RunSolver::class, fn (RunSolver $job): bool => $job->runId === $run->id && $job->timeout === 180);
});

it('con la cola síncrona, la corrida lanzada termina con la salida del motor de prueba', function () {
    Notification::fake();

    $run = launchSolverRun();

    expect($run->status)->toBe(SolverRunStatus::Done)
        ->and($run->output['run_id'])->toBe($run->id)
        ->and($run->metrics['engine'])->toBe('fixed');

    Notification::assertSentTo($this->jefe, SolverRunFinished::class);
});

it('SV-02: dos armados de la misma carga dan la misma huella; mover una sesión o cambiar un cupo la cambia', function () {
    $fingerprint = launchLoadFingerprint();

    expect(launchLoadFingerprint())->toBe($fingerprint);

    $this->session->update(['starts_at' => '09:00', 'ends_at' => '11:00']);
    $moved = launchLoadFingerprint();

    $this->session->update(['starts_at' => '07:00', 'ends_at' => '09:00']);
    $restored = launchLoadFingerprint();

    $this->pending->update(['capacity' => 25]);

    expect($moved)->not->toBe($fingerprint)
        ->and($restored)->toBe($fingerprint)
        ->and(launchLoadFingerprint())->not->toBe($fingerprint);
});

it('SV-01: no lanza otra corrida del departamento mientras la primera está en cola o corriendo', function (SolverRunStatus $status) {
    Queue::fake();
    launchSolverRun()->update(['status' => $status]);

    expect(fn () => launchSolverRun())
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['scope' => ['Ya hay una corrida en curso de este departamento.']]))
        ->and(SolverRun::query()->count())->toBe(1);

    Queue::assertPushedTimes(RunSolver::class, 1);
})->with([SolverRunStatus::Queued, SolverRunStatus::Running]);

it('SV-01: si otra corrida del departamento entra al mismo tiempo, el índice único la detiene con el mismo mensaje', function () {
    Queue::fake();
    $inserted = false;

    SolverRun::creating(function (SolverRun $run) use (&$inserted): void {
        if ($inserted) {
            return;
        }

        $inserted = true;
        DB::table('solver_runs')->insert([
            'id' => (string) Str::uuid7(),
            'term_id' => $run->term_id,
            'modality_id' => $run->modality_id,
            'scope_kind' => 'department',
            'department_id' => $run->department_id,
            'engine' => 'fixed',
            'status' => 'queued',
            'created_by' => $run->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    expect(fn () => launchSolverRun())
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['scope' => ['Ya hay una corrida en curso de este departamento.']]));

    Queue::assertNothingPushed();
});

it('SV-01: dos corridas de la misma carrera conviven y terminar una libera al departamento', function () {
    Queue::fake();

    $first = launchSolverRun(RunScope::Career);
    $second = launchSolverRun(RunScope::Career);
    $department = launchSolverRun();
    $department->update(['status' => SolverRunStatus::Done]);
    $again = launchSolverRun();

    expect($first->scope_kind)->toBe(RunScope::Career)
        ->and($first->career_id)->toBe($this->isc->id)
        ->and($second->status)->toBe(SolverRunStatus::Queued)
        ->and($again->status)->toBe(SolverRunStatus::Queued)
        ->and(SolverRun::query()->count())->toBe(4);
});

it('SV-01: una corrida que no se movió en 20 minutos no impide lanzar otra; queda fallida y se avisa a quien la lanzó', function () {
    Queue::fake();
    Notification::fake();
    $stuck = SolverRun::factory()->create([
        'term_id' => $this->term->id,
        'modality_id' => $this->pres->id,
        'department_id' => $this->sistemas->id,
        'status' => SolverRunStatus::Running,
        'updated_at' => now()->subMinutes(21),
    ]);

    $run = launchSolverRun();

    expect($run->is($stuck))->toBeFalse()
        ->and($stuck->fresh())
        ->status->toBe(SolverRunStatus::Failed)
        ->error->toBe('No terminó en 20 minutos: se lanzó otra corrida.')
        ->finished_at->not->toBeNull();

    Notification::assertSentTo($stuck->creator, SolverRunFinished::class);
});

it('SV-01: lanzar una de carrera da por perdidas las de esa carrera que no se movieron en 20 minutos, no las de otra ni las que siguen vivas', function () {
    Queue::fake();
    Notification::fake();
    $other = Career::factory()->create(['department_id' => $this->sistemas->id]);
    $career = fn (Career $career, int $minutes): SolverRun => SolverRun::factory()->forCareer()->create([
        'term_id' => $this->term->id,
        'modality_id' => $this->pres->id,
        'career_id' => $career->id,
        'status' => SolverRunStatus::Running,
        'updated_at' => now()->subMinutes($minutes),
    ]);
    $stuck = $career($this->isc, 21);
    $alive = $career($this->isc, 5);
    $elsewhere = $career($other, 21);

    launchSolverRun(RunScope::Career);

    expect($stuck->fresh())
        ->status->toBe(SolverRunStatus::Failed)
        ->error->toBe('No terminó en 20 minutos: se lanzó otra corrida.')
        ->finished_at->not->toBeNull()
        ->and($alive->fresh()->status)->toBe(SolverRunStatus::Running)
        ->and($elsewhere->fresh()->status)->toBe(SolverRunStatus::Running);

    Notification::assertSentTo($stuck->creator, SolverRunFinished::class);
    Notification::assertNotSentTo($elsewhere->creator, SolverRunFinished::class);
});

it('SV-01: lanzar una de departamento da por perdidas las de sus carreras que no se movieron en 20 minutos, en el mismo periodo y modalidad', function () {
    Queue::fake();
    Notification::fake();
    $foreign = Career::factory()->create(['department_id' => Department::factory()->create()->id]);
    $career = fn (Career $career, array $attributes = []): SolverRun => SolverRun::factory()->forCareer()->create([
        'term_id' => $this->term->id,
        'modality_id' => $this->pres->id,
        'career_id' => $career->id,
        'status' => SolverRunStatus::Queued,
        'updated_at' => now()->subMinutes(21),
        ...$attributes,
    ]);
    $stuck = $career($this->isc);
    $foreignStuck = $career($foreign);
    $otherTerm = $career($this->isc, ['term_id' => Term::factory()->create()->id]);

    launchSolverRun();

    expect($stuck->fresh())
        ->status->toBe(SolverRunStatus::Failed)
        ->error->toBe('No terminó en 20 minutos: se lanzó otra corrida.')
        ->and($foreignStuck->fresh()->status)->toBe(SolverRunStatus::Queued)
        ->and($otherTerm->fresh()->status)->toBe(SolverRunStatus::Queued);

    Notification::assertSentTo($stuck->creator, SolverRunFinished::class);
});

it('solo lanza quien puede lanzar ese alcance: el jefe de otro departamento y el jefe sobre una carrera reciben 403', function (Closure $launch) {
    Queue::fake();

    expect($launch)->toThrow(AuthorizationException::class)
        ->and(SolverRun::query()->count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'jefe de otro departamento' => [fn () => launchSolverRun(by: User::factory()->withRole(Role::JefeDepartamento, [
        'campus_id' => test()->campus->id, 'department_id' => Department::factory()->create()->id,
    ])->create())],
    'jefe sobre una carrera' => [fn () => launchSolverRun(RunScope::Career, by: test()->jefe)],
    'coordinador sobre el departamento' => [fn () => launchSolverRun(by: test()->coordinador)],
]);

it('si no se puede encolar, la corrida queda fallida, libera al departamento y el error sale', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Sin conexión con la cola.'));

    expect(fn () => launchSolverRun())->toThrow(RuntimeException::class, 'Sin conexión con la cola.')
        ->and(SolverRun::query()->sole())
        ->status->toBe(SolverRunStatus::Failed)
        ->error->toBe('No se pudo encolar la corrida.')
        ->finished_at->not->toBeNull();
});

it('un error al guardar que no es del índice de SV-01 sale tal cual', function () {
    Queue::fake();
    SolverRun::creating(fn (): never => throw new RuntimeException('La base no respondió.'));

    expect(fn () => launchSolverRun())->toThrow(RuntimeException::class, 'La base no respondió.')
        ->and(SolverRun::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('SV-04: rechaza un motor que no existe, un límite fuera de 10 a 600 segundos o un alcance que no existe', function (Closure $launch, array $errors) {
    Queue::fake();

    expect(fn () => $launch())->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe($errors))
        ->and(SolverRun::query()->count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'motor que no existe' => [fn () => launchSolverRun(engine: 'magia'), ['engine' => ['No hay un motor de horarios «magia».']]],
    'menos de 10 segundos' => [fn () => launchSolverRun(seconds: 9), ['time_limit' => ['El tiempo máximo de una corrida va de 10 a 600 segundos.']]],
    'más de 600 segundos' => [fn () => launchSolverRun(seconds: 601), ['time_limit' => ['El tiempo máximo de una corrida va de 10 a 600 segundos.']]],
    'departamento que no existe' => [fn () => launchSolverRun(scopeId: 'no-es-uuid'), ['scope' => ['No existe ese departamento.']]],
    'carrera que no existe' => [fn () => launchSolverRun(RunScope::Career, fakeKey(9)), ['scope' => ['No existe esa carrera.']]],
]);
