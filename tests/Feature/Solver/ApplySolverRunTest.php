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
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Events\ScheduleConflictsSynced;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Actions\ApplySolverRun;
use App\Modules\Solver\Actions\ApplyTokenMismatch;
use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Actions\RequestApplySolverRun;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\ApplySolverRunJob;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Notifications\SolverRunApplied;
use App\Modules\Solver\Support\RunPayloads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Aceptar una corrida del motor (tarea 6.10; SV-05, SV-06): el jefe de la carga escribe el horario que propuso una
| corrida de departamento terminada, solo lo diferente, si la carga no cambió desde que se lanzó (SV-02). Los grupos sin
| acomodar, con violación dura o que chocarían con lo que se queda se quedan como estaban. Después se recalculan los
| choques de la modalidad.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->pres = modalityOf('PRES');
    $this->campus = Campus::factory()->create(['code' => 'L1']);
    $this->sistemas = Department::factory()->create(['name' => 'Sistemas']);
    AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $this->sistemas->id]);
    $this->isc = Career::factory()->create(['department_id' => $this->sistemas->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->isc->id]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'capacity' => 40, 'code' => 'LC-1']);

    $this->same = applyRunGroup(1);
    $this->placing = applyRunGroup(2);
    $this->moved = applyRunGroup(3);
    $this->unplaced = applyRunGroup(4);
    $this->violating = applyRunGroup(5);

    $this->sameSession = GroupSession::factory()->for($this->same)->inRoom($this->room)->on(1, '07:00', '09:00')->create();
    GroupSession::factory()->for($this->moved)->inRoom($this->room)->on(2, '07:00', '09:00')->create();
    GroupSession::factory()->for($this->unplaced)->inRoom($this->room)->on(3, '07:00', '09:00')->create();
    GroupSession::factory()->for($this->violating)->inRoom($this->room)->on(5, '07:00', '09:00')->create();

    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->sistemas->id])->create();
});

function applyRunGroup(int $semester): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'modality_id' => test()->pres->id,
        'subject_id' => Subject::factory()->create(['department_id' => test()->sistemas->id, 'theory_hours' => 2, 'practice_hours' => 0])->id,
        'owner_career_id' => test()->isc->id,
        'study_plan_id' => test()->plan->id,
        'semester' => $semester,
        'teacher_id' => Teacher::factory()->create()->id,
    ]);
}

/**
 * Una corrida de la carga de Sistemas, lanzada de verdad (su entrada y su huella son las de hoy) y terminada con la
 * salida que se le da: las sesiones por grupo `[día, inicio, fin]` en LC-1, y lo que el motor no acomodó.
 *
 * @param  array<string, list<array{int, string, string}>>  $sessions
 * @param  array<string, mixed>  $extra
 */
function doneSolverRun(array $sessions, array $extra = []): SolverRun
{
    Queue::fake();
    $run = app(LaunchSolverRun::class)->handle(test()->term, test()->pres, RunScope::Department, test()->sistemas->id, 'fixed', 60, test()->jefe);
    $proposed = [];

    foreach ($sessions as $groupId => $slots) {
        foreach ($slots as [$day, $from, $to]) {
            $proposed[] = ['group_id' => $groupId, 'day' => $day, 'starts_at' => $from, 'ends_at' => $to, 'room_id' => test()->room->id, 'kind' => 'teoria', 'changed' => true];
        }
    }

    $output = [
        'version' => 1, 'run_id' => $run->id, 'status' => 'partial', 'sessions' => $proposed, 'teacher_suggestions' => [],
        'unscheduled' => [], 'hard_violations' => [], 'soft_score' => ['total' => 12, 'by_rule' => ['SC-01' => 12]], 'relaxed_rules' => [],
        'metrics' => ['elapsed_seconds' => 1.0, 'engine' => 'fixed', 'engine_version' => '1.0'], 'log' => [],
        ...$extra,
    ];

    $run->forceFill([...RunPayloads::attributes($run->id, 'output', $output), 'status' => SolverRunStatus::Done, 'finished_at' => now()])->save();

    return $run->refresh();
}

function applySolverRun(SolverRun $run, ?User $by = null): array
{
    return app(ApplySolverRun::class)->handle($run, $by ?? test()->jefe);
}

/**
 * @return list<string>
 */
function appliedSessionsOf(Group $group): array
{
    return $group->sessions()->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (GroupSession $session): string => "{$session->day} ".substr($session->starts_at, 0, 5).'-'.substr($session->ends_at, 0, 5))
        ->all();
}

function standardRun(): SolverRun
{
    return doneSolverRun([
        test()->same->id => [[1, '07:00', '08:00'], [1, '08:00', '09:00']],
        test()->placing->id => [[3, '10:00', '12:00']],
        test()->moved->id => [[4, '07:00', '09:00']],
        test()->violating->id => [[1, '10:00', '12:00']],
    ], [
        'status' => 'infeasible',
        'unscheduled' => [['group_id' => test()->unplaced->id, 'reasons' => ['HC-12: no caben sus horas en la semana.']]],
        'hard_violations' => [['rule' => 'HC-05', 'group_ids' => [test()->violating->id], 'message' => 'El salón LC-1 es chico para el grupo.']],
    ]);
}

it('SV-05: escribe solo lo diferente, con bitácora por grupo, deja la corrida aplicada y recalcula los choques', function () {
    $run = standardRun();

    $summary = applySolverRun($run);

    expect($summary)->toMatchArray(['groups' => 2, 'sessions' => 2, 'removed' => 1])
        ->and(array_keys($summary['kept']))->toEqualCanonicalizing([$this->unplaced->id, $this->violating->id])
        ->and(appliedSessionsOf($this->same))->toBe(['1 07:00-09:00'])
        ->and(GroupSession::query()->whereKey($this->sameSession->id)->exists())->toBeTrue()
        ->and(appliedSessionsOf($this->placing))->toBe(['3 10:00-12:00'])
        ->and(appliedSessionsOf($this->moved))->toBe(['4 07:00-09:00'])
        ->and(Activity::query()->where('event', ReplaceGroupSessions::EVENT)->pluck('subject_id')->all())->toEqualCanonicalizing([$this->placing->id, $this->moved->id])
        ->and($run->fresh())
        ->status->toBe(SolverRunStatus::Applied)
        ->applied_by->toBe($this->jefe->id)
        ->applied_at->not->toBeNull()
        ->and(Activity::query()->where('subject_id', $run->id)->where('event', 'applied')->sole())
        ->causer_id->toBe($this->jefe->id)
        ->attribute_changes->toArray()->toBe(['attributes' => ['status' => 'applied'], 'old' => ['status' => 'done']])
        ->and(Cache::get(SyncScheduleConflicts::stampKey($this->term->id, $this->pres->id)))->not->toBeNull();
});

it('SV-06: los grupos sin acomodar y los de una violación dura se quedan como estaban, con su motivo', function () {
    $summary = applySolverRun(standardRun());

    expect(appliedSessionsOf($this->unplaced))->toBe(['3 07:00-09:00'])
        ->and(appliedSessionsOf($this->violating))->toBe(['5 07:00-09:00'])
        ->and($summary['kept'])->toEqual([
            $this->unplaced->id => ['HC-12: no caben sus horas en la semana.'],
            $this->violating->id => ['HC-05: El salón LC-1 es chico para el grupo.'],
        ]);
});

it('SV-02: una entrada vencida no aplica y no escribe nada', function () {
    $run = standardRun();
    $this->sameSession->update(['starts_at' => '09:00', 'ends_at' => '11:00']);

    expect(fn () => applySolverRun($run))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => [ApplySolverRun::STALE_MESSAGE]]))
        ->and($run->fresh()->status)->toBe(SolverRunStatus::Done)
        ->and(appliedSessionsOf($this->placing))->toBe([])
        ->and(Activity::query()->where('event', ReplaceGroupSessions::EVENT)->exists())->toBeFalse();
});

it('SV-02: aplicar una corrida deja vencida a la otra corrida lista de la misma carga', function () {
    $first = standardRun();
    $second = doneSolverRun([$this->placing->id => [[2, '10:00', '12:00']]]);

    applySolverRun($first);

    expect(fn () => applySolverRun($second))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => [ApplySolverRun::STALE_MESSAGE]]))
        ->and($second->fresh()->status)->toBe(SolverRunStatus::Done);
});

it('solo se acepta una corrida de departamento terminada, y solo el jefe de esa carga la acepta', function () {
    $run = standardRun();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
    $otherHead = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => departmentKey(70)])->create();

    expect(fn () => applySolverRun($run, $coordinador))->toThrow(AuthorizationException::class)
        ->and(fn () => applySolverRun($run, $otherHead))->toThrow(AuthorizationException::class)
        ->and($run->fresh()->status)->toBe(SolverRunStatus::Done);

    $run->update(['status' => SolverRunStatus::Discarded]);

    expect(fn () => applySolverRun($run->fresh()))->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => ['Solo se acepta una corrida terminada.']]));

    $career = SolverRun::factory()->forCareer()->done()->create(['term_id' => $this->term->id, 'career_id' => $this->isc->id]);

    expect(fn () => applySolverRun($career))->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => ['Una corrida de carrera es para explorar: no se acepta.']]));
});

it('SV-05: pedir aceptar deja la corrida en applying y despacha el job sin escribir todavía', function () {
    $run = standardRun();
    Queue::fake();

    app(RequestApplySolverRun::class)->handle($run, $this->jefe);

    Queue::assertPushed(ApplySolverRunJob::class, fn (ApplySolverRunJob $job): bool => $job->runId === $run->id && $job->userId === $this->jefe->id);
    expect($run->fresh()->status)->toBe(SolverRunStatus::Applying)
        ->and($this->placing->sessions()->count())->toBe(0);
});

it('SV-05: el job deja la corrida aplicada, escribe y avisa a quien aceptó', function () {
    $run = standardRun();
    Queue::fake();
    app(RequestApplySolverRun::class)->handle($run, $this->jefe);
    Notification::fake();

    (new ApplySolverRunJob($run->id, $this->jefe->id, $run->fresh()->apply_token))->handle(app(ApplySolverRun::class));

    expect($run->fresh()->status)->toBe(SolverRunStatus::Applied)
        ->and($this->placing->sessions()->count())->toBeGreaterThan(0);
    Notification::assertSentTo($this->jefe, SolverRunApplied::class);
});

it('SV-02: con la entrada vencida el request avisa y el job regresa la corrida a done con el error', function () {
    $run = standardRun();
    Queue::fake();
    app(RequestApplySolverRun::class)->handle($run, $this->jefe);
    GroupSession::factory()->for($this->unplaced)->inRoom($this->room)->on(5, '13:00', '14:00')->create();
    Notification::fake();

    (new ApplySolverRunJob($run->id, $this->jefe->id, $run->fresh()->apply_token))->handle(app(ApplySolverRun::class));

    $fresh = $run->fresh();
    expect($fresh->status)->toBe(SolverRunStatus::Done)
        ->and($fresh->metrics['apply_error'])->toBe(ApplySolverRun::STALE_MESSAGE)
        ->and($this->placing->sessions()->count())->toBe(0);
    Notification::assertSentTo($this->jefe, SolverRunApplied::class);

    expect(fn () => app(RequestApplySolverRun::class)->handle($fresh, $this->jefe))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => [ApplySolverRun::STALE_MESSAGE]]));
});

it('SV-01: mientras una corrida se aplica no se lanza otra del departamento', function () {
    $run = standardRun();
    Queue::fake();
    app(RequestApplySolverRun::class)->handle($run, $this->jefe);

    expect(fn () => app(LaunchSolverRun::class)->handle($this->term, $this->pres, RunScope::Department, $this->sistemas->id, 'fixed', 60, $this->jefe))
        ->toThrow(ValidationException::class);
});

it('pedir aceptar exige la corrida terminada y al jefe de la carga', function () {
    $run = standardRun();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
    Queue::fake();

    expect(fn () => app(RequestApplySolverRun::class)->handle($run, $coordinador))->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
});

it('SV-05: sobre una carga que ya no se edita no acepta, aunque no haya nada que escribir', function (?LoadStatus $status) {
    $run = doneSolverRun([$this->same->id => [[1, '07:00', '09:00']]]);
    $load = AcademicLoad::query()->ofDepartment($this->term->id, $this->pres->id, $this->sistemas->id)->sole();
    $status === null ? $load->delete() : $load->forceFill(['status' => $status])->save();

    expect(fn () => applySolverRun($run))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['run'][0])->toStartWith('La carga ya no se puede editar:'))
        ->and($run->fresh()->status)->toBe(SolverRunStatus::Done)
        ->and(Activity::query()->where('subject_id', $run->id)->where('event', 'applied')->exists())->toBeFalse();
})->with([
    'cerrada' => [LoadStatus::Closed],
    'aprobada' => [LoadStatus::Approved],
    'sin carga' => [null],
]);

it('de dos aceptaciones de la misma corrida, la segunda falla: revisa el estado con la corrida bloqueada', function () {
    $run = standardRun();
    $second = $run->fresh();

    applySolverRun($run);

    expect(fn () => applySolverRun($second))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['run' => ['Solo se acepta una corrida terminada.']]))
        ->and(Activity::query()->where('subject_id', $run->id)->where('event', 'applied')->count())->toBe(1);
});

it('si el recálculo de choques falla después de escribir, la corrida queda aplicada y el resumen lo dice', function () {
    $run = standardRun();
    Event::listen(ScheduleConflictsSynced::class, fn () => throw new RuntimeException('Valkey no responde.'));

    $summary = applySolverRun($run);

    expect($summary['conflicts_pending'])->toBeTrue()
        ->and($run->fresh()->status)->toBe(SolverRunStatus::Applied)
        ->and(appliedSessionsOf($this->placing))->toBe(['3 10:00-12:00']);
});

it('SV-05: un job con una ficha vieja no aplica la corrida que se volvió a pedir; el de la ficha nueva sí', function () {
    $run = standardRun();
    Queue::fake();
    Notification::fake();
    app(RequestApplySolverRun::class)->handle($run, $this->jefe);
    $old = $run->fresh()->apply_token;
    ApplySolverRunJob::release($run->id, 'Se perdió el job.');
    app(RequestApplySolverRun::class)->handle($run->fresh(), $this->jefe);
    $new = $run->fresh()->apply_token;

    (new ApplySolverRunJob($run->id, $this->jefe->id, $old))->handle(app(ApplySolverRun::class));
    (new ApplySolverRunJob($run->id, $this->jefe->id, $old))->failed(new RuntimeException('Se acabó el tiempo.'));

    expect($new)->not->toBe($old)
        ->and($run->fresh()->status)->toBe(SolverRunStatus::Applying)
        ->and($this->placing->sessions()->count())->toBe(0);

    (new ApplySolverRunJob($run->id, $this->jefe->id, $new))->handle(app(ApplySolverRun::class));

    expect($run->fresh()->status)->toBe(SolverRunStatus::Applied)
        ->and(Activity::query()->where('subject_id', $run->id)->where('event', 'applied')->sole()->attribute_changes['old']['status'])->toBe('applying');
});

it('SV-05: con la fila bloqueada, una corrida que se aplica con otra ficha no se toca', function () {
    $run = standardRun();
    Queue::fake();
    app(RequestApplySolverRun::class)->handle($run, $this->jefe);
    $seen = $run->fresh()->forceFill(['apply_token' => (string) Str::uuid7()]);

    expect(fn () => applySolverRun($seen))->toThrow(ApplyTokenMismatch::class)
        ->and($run->fresh()->status)->toBe(SolverRunStatus::Applying)
        ->and($this->placing->sessions()->count())->toBe(0);
});
