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
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Engines\SolverEngine;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Jobs\ComputeChangeAlternatives;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Notifications\ChangeRequestApproved;
use App\Modules\Staffing\Notifications\ChangeRequestPendingApplication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Alternativas del motor para una solicitud de cambio (tarea 8.7; CR-02 y CR-03). Para una solicitud con choques duros,
| el jefe pide al motor acomodos sin choques: (a) la cadena, con la propuesta fija y los grupos afectados de su
| departamento moviéndose, y (b) el grupo pedido solo, con todo lo demás fijo. Elegir uno aprueba la solicitud por el
| flujo de siempre y lo deja registrado; si algo cambió desde que se calcularon, «Esto cambió…».
*/

/**
 * Un departamento con dos grupos del mismo docente (materias de 4 h de teoría): G de lunes a jueves a las 7:00 en el
 * salón A-1 y H a las 9:00 en A-2, con la carga en asignación, y los usuarios del flujo.
 *
 * @return array{term: Term, campus: Campus, department: Department, career: Career, plan: StudyPlan, teacher: Teacher, teacherUser: User, group: Group, other: Group, roomA: Room, roomB: Room, load: AcademicLoad, jefe: User, coordinador: User}
 */
function alternativesScenario(): array
{
    $term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $campus = Campus::factory()->create();
    $department = Department::factory()->create();
    $career = Career::factory()->create(['department_id' => $department->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    $teacherUser = User::factory()->withRole(Role::Docente)->create();
    $teacher = Teacher::factory()->create(['user_id' => $teacherUser->id]);
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();
    $building = Building::factory()->create(['campus_id' => $campus->id]);
    $roomA = Room::factory()->create(['building_id' => $building->id, 'code' => 'A-1', 'capacity' => 40]);
    $roomB = Room::factory()->create(['building_id' => $building->id, 'code' => 'A-2', 'capacity' => 40]);

    $make = function (string $code, int $semester) use ($term, $campus, $department, $career, $plan, $teacher): Group {
        return Group::factory()->create([
            'term_id' => $term->id, 'campus_id' => $campus->id, 'modality_id' => modalityOf('PRES')->id,
            'subject_id' => Subject::factory()->create(['department_id' => $department->id, 'theory_hours' => 4, 'practice_hours' => 0])->id,
            'owner_career_id' => $career->id, 'study_plan_id' => $plan->id, 'semester' => $semester, 'code' => $code, 'capacity' => 30, 'teacher_id' => $teacher->id,
        ]);
    };

    $group = $make('G', 1);
    $other = $make('H', 3);
    alternativesWeek($group, '07:00', $roomA);
    alternativesWeek($other, '09:00', $roomB);

    return [
        'term' => $term, 'campus' => $campus, 'department' => $department, 'career' => $career, 'plan' => $plan, 'teacher' => $teacher,
        'teacherUser' => $teacherUser, 'group' => $group, 'other' => $other, 'roomA' => $roomA, 'roomB' => $roomB,
        'load' => AcademicLoad::factory()->create(['term_id' => $term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $department->id, 'status' => LoadStatus::Staffing]),
        'jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create(),
        'coordinador' => User::factory()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id])->create(),
    ];
}

/**
 * Cuatro sesiones de una hora, de lunes a jueves a la hora dada.
 */
function alternativesWeek(Group $group, string $start, ?Room $room = null): void
{
    $end = sprintf('%02d:00', (int) substr($start, 0, 2) + 1);

    foreach ([1, 2, 3, 4] as $day) {
        GroupSession::factory()->for($group)->when($room !== null, fn ($factory) => $factory->inRoom($room))->on($day, $start, $end)->create();
    }
}

/**
 * Pasa el grupo a una materia de otro departamento (y a ese departamento).
 */
function alternativesToOtherDepartment(Group $group): void
{
    $subject = Subject::factory()->create(['theory_hours' => 4, 'practice_hours' => 0]);
    $group->update(['subject_id' => $subject->id, 'department_id' => $subject->department_id]);
}

/**
 * El docente pide mover G a las 9:00 de lunes a jueves, que es la hora de H: choca con su propio grupo (HC-01).
 */
function alternativesRequest(array $s, string $start = '09:00'): ChangeRequest
{
    $end = sprintf('%02d:00', (int) substr($start, 0, 2) + 1);
    $sessions = array_map(fn (int $day): array => ['day' => $day, 'starts_at' => $start, 'ends_at' => $end], [1, 2, 3, 4]);

    return app(RequestChange::class)->handle($s['group'], $s['teacherUser'], ChangeRequestKind::Schedule, 'A las 9', 'Otro trabajo', ['sessions' => $sessions])->fresh();
}

/**
 * El jefe pide las alternativas; con la cola síncrona de las pruebas, el job corre al confirmar y la solicitud vuelve
 * con lo que guardó.
 */
function alternativesPropose(ChangeRequest $request, User $jefe): ChangeRequest
{
    return app(ProposeChangeAlternatives::class)->handle($request, $jefe)->fresh();
}

/**
 * @param  Closure(): mixed  $call
 */
function alternativesError(Closure $call, string $key): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors()[$key][0] ?? 'sin '.$key;
    }

    return 'sin error';
}

/**
 * @return array<string, mixed>
 */
function alternativesItem(ChangeRequest $request, string $kind): array
{
    return collect($request->alternatives['items'])->firstWhere('kind', $kind);
}

/**
 * Las horas de inicio de las sesiones de un grupo en una alternativa, por día.
 *
 * @param  array<string, mixed>  $item
 * @return array<int, string>
 */
function alternativesStarts(array $item, Group $group): array
{
    $sessions = collect($item['groups'])->firstWhere('group_id', $group->id)['sessions'] ?? [];

    return collect($sessions)->mapWithKeys(fn (array $session): array => [$session['day'] => $session['starts_at']])->sortKeys()->all();
}

it('CR-02: propone la cadena (la propuesta fija y H se mueve) y el grupo solo, sin choques y sin escribir sesiones', function () {
    $s = alternativesScenario();
    $request = alternativesRequest($s);

    $request = alternativesPropose($request, $s['jefe']);
    $chain = alternativesItem($request, 'chain');
    $alone = alternativesItem($request, 'alone');

    expect($request->alternatives['items'])->toHaveCount(3)
        ->and($request->alternatives['status'])->toBe('ready')
        ->and($request->alternatives['calculation_id'])->toBeString()
        ->and($request->alternatives['requested_by'])->toBe($s['jefe']->id)
        ->and($request->alternatives['message'])->toBeNull()
        ->and($request->alternatives['stop'])->toBe('complete')
        ->and(collect($request->alternatives['items'])->pluck('id')->unique())->toHaveCount(3)
        ->and($chain['input_problems'])->toBe([])
        ->and($request->alternatives['chosen'])->toBeNull()
        ->and(alternativesStarts($chain, $s['group']))->toBe([1 => '09:00', 2 => '09:00', 3 => '09:00', 4 => '09:00'])
        ->and(collect(alternativesStarts($chain, $s['other']))->contains('09:00'))->toBeFalse()
        ->and(array_column($chain['affected_groups'], 'code'))->toBe(['H'])
        ->and(array_column($chain['affected_teachers'], 'id'))->toBe([$s['teacher']->id])
        ->and($chain['movable_group_ids'])->toEqualCanonicalizing([$s['group']->id, $s['other']->id])
        ->and($chain['fingerprint'])->toBeString()
        ->and($chain['score'])->toBeInt()
        ->and($chain['soft'])->toBeArray()
        ->and(collect($chain['groups'])->firstWhere('group_id', $s['group']->id)['sessions'][0])->toMatchArray(['room_id' => $s['roomA']->id, 'room' => 'A-1'])
        ->and(collect($chain['groups'])->firstWhere('group_id', $s['group']->id)['sessions'][0]['replaces_session_id'])->not->toBeNull()
        ->and($alone['movable_group_ids'])->toBe([$s['group']->id])
        ->and($alone['affected_groups'])->toBe([])
        ->and(alternativesStarts($alone, $s['group']))->not->toContain('09:00')
        ->and(alternativesStarts($alone, $s['group']))->not->toBe([1 => '07:00', 2 => '07:00', 3 => '07:00', 4 => '07:00'])
        ->and(GroupSession::query()->where('group_id', $s['other']->id)->pluck('starts_at')->map(fn ($time) => substr((string) $time, 0, 5))->unique()->all())->toBe(['09:00'])
        ->and($request->status)->toBe(ChangeRequestStatus::Pending)
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'alternatives_proposed')->exists())->toBeTrue();
});

it('CR-02: en un cambio de salón, la cadena deja las horas con el salón nuevo y mueve al grupo del departamento que lo ocupa', function () {
    $s = alternativesScenario();
    $occupant = Group::factory()->create([
        'term_id' => $s['term']->id, 'campus_id' => $s['campus']->id, 'modality_id' => modalityOf('PRES')->id,
        'subject_id' => Subject::factory()->create(['department_id' => $s['department']->id, 'theory_hours' => 4, 'practice_hours' => 0])->id,
        'owner_career_id' => $s['career']->id, 'study_plan_id' => $s['plan']->id, 'semester' => 5, 'code' => 'J', 'capacity' => 30,
    ]);
    alternativesWeek($occupant, '07:00', $s['roomB']);
    $request = app(RequestChange::class)->handle($s['group'], $s['teacherUser'], ChangeRequestKind::Room, 'Al A-2', 'Más grande', ['room' => 'A-2'])->fresh();

    $chain = alternativesItem(alternativesPropose($request, $s['jefe']), 'chain');
    $mine = collect($chain['groups'])->firstWhere('group_id', $s['group']->id)['sessions'];

    expect(array_unique(array_column($mine, 'starts_at')))->toBe(['07:00'])
        ->and(array_unique(array_column($mine, 'room')))->toBe(['A-2'])
        ->and(array_column($mine, 'replaces_session_id'))->toEqualCanonicalizing(GroupSession::query()->where('group_id', $s['group']->id)->pluck('id')->all())
        ->and(array_column($chain['affected_groups'], 'code'))->toBe(['J']);
});

it('CR-02: los grupos de otro departamento nunca se mueven: sin cadena, solo el grupo pedido', function () {
    $s = alternativesScenario();
    alternativesToOtherDepartment($s['other']);

    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);

    expect(collect($request->alternatives['items'])->pluck('kind')->unique()->all())->toBe(['alone']);
});

it('CR-02: sin acomodo sin choques lo registra, y el jefe solo puede rechazar', function () {
    $s = alternativesScenario();
    alternativesToOtherDepartment($s['other']);

    foreach ([1, 2, 3, 4] as $day) {
        TeacherAvailability::factory()->for($s['teacher'])->create(['term_id' => $s['term']->id, 'day' => $day, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        TeacherAvailability::factory()->for($s['teacher'])->create(['term_id' => $s['term']->id, 'day' => $day, 'starts_at' => '10:00', 'ends_at' => '21:00']);
    }

    foreach ([5, 6] as $day) {
        TeacherAvailability::factory()->for($s['teacher'])->create(['term_id' => $s['term']->id, 'day' => $day, 'starts_at' => '07:00', 'ends_at' => '21:00']);
    }

    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);

    expect($request->alternatives['items'])->toBe([])
        ->and($request->alternatives['status'])->toBe('none')
        ->and($request->alternatives['message'])->toBe('El motor no encontró acomodos sin choques.')
        ->and($request->alternatives['stop'])->toBe('no_placement')
        ->and(fn () => app(ApproveChangeRequest::class)->handle($request, $s['jefe']))->toThrow(ValidationException::class)
        ->and(app(RejectChangeRequest::class)->handle($request, $s['jefe'], 'No hay lugar')->status)->toBe(ChangeRequestStatus::Rejected);
});

it('CR-02: no pide alternativas para una solicitud sin choques duros ni para una que solo trae texto', function (string $case) {
    $s = alternativesScenario();
    $request = $case === 'sin choques'
        ? alternativesRequest($s, '11:00')
        : app(RequestChange::class)->handle($s['group'], $s['teacherUser'], ChangeRequestKind::Schedule, 'Otra hora', 'Motivo')->fresh();

    expect(alternativesError(fn () => app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']), 'status'))
        ->toBe($case === 'sin choques' ? ProposeChangeAlternatives::NO_HARD_CLASHES : ProposeChangeAlternatives::TEXT_ONLY)
        ->and($request->fresh()->alternatives)->toBeNull();
})->with(['sin choques', 'solo texto']);

it('CR-03: elegir una alternativa aprueba la solicitud, pasa la carga a ajustes y la deja registrada', function () {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $chain = alternativesItem($request, 'chain');

    $approved = app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], $chain['id'], 'Con la cadena');

    expect($approved->status)->toBe(ChangeRequestStatus::Approved)
        ->and($approved->decided_by)->toBe($s['jefe']->id)
        ->and($approved->decision_comment)->toBe('Con la cadena')
        ->and($approved->fresh()->alternatives['chosen'])->toMatchArray(['id' => $chain['id'], 'chosen_by' => $s['jefe']->id])
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and(GroupSession::query()->where('group_id', $s['group']->id)->pluck('starts_at')->map(fn ($time) => substr((string) $time, 0, 5))->unique()->all())->toBe(['07:00'])
        ->and(Activity::query()->where('subject_id', $request->id)->whereIn('event', ['approved', 'alternative_chosen'])->count())->toBe(2);
});

it('CR-03: si el horario cambió desde que se calcularon, no aprueba: «Esto cambió…»', function () {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $alone = alternativesItem($request, 'alone');
    GroupSession::query()->where('group_id', $s['other']->id)->where('day', 1)->update(['starts_at' => '12:00', 'ends_at' => '13:00']);

    try {
        app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], $alone['id']);
        $this->fail('Debió rechazar la alternativa vencida.');
    } catch (ValidationException $e) {
        expect($e->errors()['alternative'][0])->toStartWith('Esto cambió');
    }

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Pending)
        ->and($request->fresh()->alternatives['chosen'])->toBeNull();
});

it('CR-03: una alternativa que no existe no se elige', function () {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);

    expect(alternativesError(fn () => app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], 'no-existe'), 'alternative'))->toBe(ChooseChangeAlternative::NOT_FOUND);
});

it('CR-03: una alternativa de un cálculo anterior no se elige, aunque el nuevo tenga una del mismo tipo', function () {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $old = alternativesItem($request, 'alone');
    $request = alternativesPropose($request, $s['jefe']);

    expect(collect($request->alternatives['items'])->pluck('id'))->not->toContain($old['id'])
        ->and(alternativesError(fn () => app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], $old['id']), 'alternative'))->toBe(ChooseChangeAlternative::NOT_FOUND)
        ->and($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
});

it('CR-03: con los candados compara la huella barata de los grupos; si no cuadra, «Esto cambió…»', function () {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $alternatives = $request->alternatives;
    $alternatives['items'][0]['sessions_fingerprint'] = 'otra';
    $request->forceFill(['alternatives' => $alternatives])->save();

    expect(alternativesError(fn () => app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], $alternatives['items'][0]['id']), 'alternative'))->toBe(ChooseChangeAlternative::STALE)
        ->and($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
});

it('CR-03: no elige con la solicitud ya decidida ni con la carga fuera de asignación o ajustes', function (string $case) {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $id = $request->alternatives['items'][0]['id'];
    $case === 'decidida'
        ? app(RejectChangeRequest::class)->handle($request, $s['jefe'], 'No')
        : $s['load']->update(['status' => LoadStatus::Closed]);

    expect(alternativesError(fn () => app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], $id), 'status'))
        ->toBe($case === 'decidida' ? 'La solicitud ya no está pendiente.' : 'La carga del departamento está en '.LoadStatus::Closed->label().': solo se deciden solicitudes con la carga en asignación o en ajustes.');
})->with(['decidida', 'carga cerrada']);

it('CR-03: el aviso dice que se aprobó con otro acomodo y cómo queda, a quien pidió y a quien aplica', function () {
    Notification::fake();
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $alone = alternativesItem($request, 'alone');

    app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], $alone['id']);

    Notification::assertSentTo($s['teacherUser'], ChangeRequestApproved::class, function (ChangeRequestApproved $notice) use ($s, $alone): bool {
        $message = $notice->message($s['teacherUser']);
        $start = Minutes::labelOf($alone['groups'][0]['sessions'][0]['starts_at']);

        $end = Minutes::labelOf($alone['groups'][0]['sessions'][0]['ends_at']);
        $room = $alone['groups'][0]['sessions'][0]['room'];

        return $message->title === 'Tu solicitud de cambio se aprobó con otro acomodo' && str_contains($message->body, "Con otro acomodo: G queda lunes, martes, miércoles y jueves de {$start} a {$end} en {$room}");
    });
    Notification::assertSentTo($s['coordinador'], ChangeRequestPendingApplication::class,
        fn (ChangeRequestPendingApplication $notice): bool => $notice->message($s['coordinador'])->title === 'Hay un cambio aprobado por aplicar, con otro acomodo');
});

it('CR-02: una dura previa que no depende del acomodo (HC-08) no deja sin alternativas y sale como problema de la entrada', function () {
    $s = alternativesScenario();
    TeacherPosition::query()->where('teacher_id', $s['teacher']->id)->delete();
    TeacherPosition::factory()->for($s['teacher'])->asignatura(2)->create();

    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);

    expect($request->alternatives['status'])->toBe('ready')
        ->and(collect($request->alternatives['items'])->flatMap(fn (array $item) => array_column($item['input_problems'], 'rule'))->unique()->values()->all())->toBe(['HC-08'])
        ->and(collect($request->alternatives['items'])->flatMap(fn (array $item) => $item['previous_problems'])->all())->toBe([]);
});

it('CR-02: en la cola: deja el cálculo en curso, despacha un job por solicitud y no pide otro mientras corre', function () {
    Queue::fake();
    $s = alternativesScenario();
    $request = alternativesRequest($s);

    app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']);
    $alternatives = $request->fresh()->alternatives;

    expect($alternatives)->toMatchArray(['status' => 'computing', 'requested_by' => $s['jefe']->id])
        ->and($alternatives['requested_at'])->not->toBeNull()
        ->and(ChangeAlternativesStatus::of($alternatives))->toBe(ChangeAlternativesStatus::Computing)
        ->and(alternativesError(fn () => app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']), 'alternatives'))->toBe('Las alternativas de esta solicitud ya se están calculando.');

    Queue::assertPushed(ComputeChangeAlternatives::class, fn (ComputeChangeAlternatives $job): bool => $job->changeRequestId === $request->id && $job->calculationId === $alternatives['calculation_id']);
    Queue::assertPushed(ComputeChangeAlternatives::class, 1);
});

it('CR-02: la espera en la cola no vence el cálculo antes de QUEUE_STALE_AFTER; uno vencido se vuelve a pedir y un job viejo no pisa el nuevo', function () {
    Queue::fake();
    $s = alternativesScenario();
    $request = alternativesRequest($s);
    app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']);
    $first = $request->fresh()->alternatives;

    $this->travel(ComputeChangeAlternatives::STALE_AFTER + 1)->seconds();

    expect(ChangeAlternativesStatus::of($first))->toBe(ChangeAlternativesStatus::Computing)
        ->and(ChangeAlternativesStatus::isQueued($first))->toBeTrue();

    $this->travel(ComputeChangeAlternatives::QUEUE_STALE_AFTER)->seconds();

    expect(ChangeAlternativesStatus::of($first))->toBe(ChangeAlternativesStatus::Failed)
        ->and(ChangeAlternativesStatus::messageOf($first))->toBe(ChangeAlternativesStatus::STALE_MESSAGE);

    app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']);
    $second = $request->fresh()->alternatives;
    app(ProposeChangeAlternatives::class)->compute($request->id, $first['calculation_id']);

    expect($second['calculation_id'])->not->toBe($first['calculation_id'])
        ->and($request->fresh()->alternatives)->toBe($second);

    app(ProposeChangeAlternatives::class)->compute($request->id, $second['calculation_id']);

    expect($request->fresh()->alternatives['status'])->toBe('ready')
        ->and($request->fresh()->alternatives['started_at'])->not->toBeNull();
});

it('CR-02: un job tardío de un cálculo vencido no guarda nada, aunque nadie lo haya vuelto a pedir', function () {
    Queue::fake();
    $s = alternativesScenario();
    $request = alternativesRequest($s);
    app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']);
    $alternatives = $request->fresh()->alternatives;

    $this->travel(ComputeChangeAlternatives::QUEUE_STALE_AFTER + 1)->seconds();
    app(ProposeChangeAlternatives::class)->compute($request->id, $alternatives['calculation_id']);

    expect($request->fresh()->alternatives)->toBe($alternatives)
        ->and(ChangeAlternativesStatus::of($request->fresh()->alternatives))->toBe(ChangeAlternativesStatus::Failed)
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'alternatives_proposed')->exists())->toBeFalse();
});

it('CR-02: un cálculo que empezó y no terminó en STALE_AFTER se da por fallido', function () {
    $alternatives = ['status' => 'computing', 'calculation_id' => 'c', 'requested_at' => now()->toIso8601String(), 'started_at' => now()->toIso8601String()];

    $this->travel(ComputeChangeAlternatives::STALE_AFTER + 1)->seconds();

    expect(ChangeAlternativesStatus::of($alternatives))->toBe(ChangeAlternativesStatus::Failed)
        ->and(ChangeAlternativesStatus::isQueued($alternatives))->toBeFalse();
});

it('CR-02: si la solicitud se decide mientras se calculan, el cálculo queda fallido sin alternativas', function () {
    Queue::fake();
    $s = alternativesScenario();
    $request = alternativesRequest($s);
    app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']);
    $calculation = $request->fresh()->alternatives['calculation_id'];
    app(RejectChangeRequest::class)->handle($request->fresh(), $s['jefe'], 'No');

    app(ProposeChangeAlternatives::class)->compute($request->id, $calculation);
    $alternatives = $request->fresh()->alternatives;

    expect($alternatives['status'])->toBe('failed')
        ->and($alternatives)->not->toHaveKey('items');
});

it('CR-03: si un grupo fijo de la entrada se mueve entre la revisión y los candados (mismo docente, a la hora de la alternativa), «Esto cambió…»', function () {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $alone = alternativesItem($request, 'alone');
    $slot = $alone['groups'][0]['sessions'][0];
    $moved = false;
    DB::listen(function ($query) use (&$moved, $s, $slot): void {
        if (! $moved && str_contains($query->sql, 'for share')) {
            $moved = true;
            DB::table('group_sessions')->where('group_id', $s['other']->id)->update(['starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at']]);
        }
    });

    expect($alone['checked_group_ids'])->toContain($s['other']->id)
        ->and(alternativesError(fn () => app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], $alone['id']), 'alternative'))->toBe(ChooseChangeAlternative::STALE)
        ->and($moved)->toBeTrue()
        ->and($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
});

it('CR-02: si el motor falla, el cálculo queda fallido con su motivo', function () {
    $s = alternativesScenario();
    app()->instance(EngineRegistry::class, new EngineRegistry([new class implements SolverEngine
    {
        public function key(): string
        {
            return PhpHeuristicEngine::KEY;
        }

        public function label(): string
        {
            return 'Se cae';
        }

        public function supportedVersions(): array
        {
            return [1];
        }

        public function checksHardRules(): bool
        {
            return true;
        }

        public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
        {
            throw new RuntimeException('El motor se cayó.');
        }
    }]));

    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);

    expect($request->alternatives['status'])->toBe('failed')
        ->and($request->alternatives['message'])->toBe(ComputeChangeAlternatives::FAILED_MESSAGE)
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'alternatives_proposed')->sole()->properties['status'])->toBe('failed');
});

it('alcance: el jefe de otro departamento, el coordinador y el docente no piden ni eligen alternativas', function (string $who) {
    $s = alternativesScenario();
    $request = alternativesPropose(alternativesRequest($s), $s['jefe']);
    $actor = match ($who) {
        'otro jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create(),
        'coordinador' => $s['coordinador'],
        'docente' => $s['teacherUser'],
    };

    expect(fn () => app(ProposeChangeAlternatives::class)->handle($request, $actor))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChooseChangeAlternative::class)->handle($request, $actor, $request->alternatives['items'][0]['id']))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
})->with(['otro jefe', 'coordinador', 'docente']);
