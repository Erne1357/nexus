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
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Auth\Access\AuthorizationException;
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

    $request = app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']);
    $chain = alternativesItem($request, 'chain');
    $alone = alternativesItem($request, 'alone');

    expect($request->alternatives['items'])->toHaveCount(3)
        ->and($request->alternatives['message'])->toBeNull()
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

    $chain = alternativesItem(app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']), 'chain');
    $mine = collect($chain['groups'])->firstWhere('group_id', $s['group']->id)['sessions'];

    expect(array_unique(array_column($mine, 'starts_at')))->toBe(['07:00'])
        ->and(array_unique(array_column($mine, 'room')))->toBe(['A-2'])
        ->and(array_column($mine, 'replaces_session_id'))->toEqualCanonicalizing(GroupSession::query()->where('group_id', $s['group']->id)->pluck('id')->all())
        ->and(array_column($chain['affected_groups'], 'code'))->toBe(['J']);
});

it('CR-02: los grupos de otro departamento nunca se mueven: sin cadena, solo el grupo pedido', function () {
    $s = alternativesScenario();
    alternativesToOtherDepartment($s['other']);

    $request = app(ProposeChangeAlternatives::class)->handle(alternativesRequest($s), $s['jefe']);

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

    $request = app(ProposeChangeAlternatives::class)->handle(alternativesRequest($s), $s['jefe']);

    expect($request->alternatives['items'])->toBe([])
        ->and($request->alternatives['message'])->toBe('El motor no encontró acomodos sin choques.')
        ->and(fn () => app(ApproveChangeRequest::class)->handle($request, $s['jefe']))->toThrow(ValidationException::class)
        ->and(app(RejectChangeRequest::class)->handle($request, $s['jefe'], 'No hay lugar')->status)->toBe(ChangeRequestStatus::Rejected);
});

it('CR-02: no pide alternativas para una solicitud sin choques duros ni para una que solo trae texto', function (string $case) {
    $s = alternativesScenario();
    $request = $case === 'sin choques'
        ? alternativesRequest($s, '11:00')
        : app(RequestChange::class)->handle($s['group'], $s['teacherUser'], ChangeRequestKind::Schedule, 'Otra hora', 'Motivo')->fresh();

    app(ProposeChangeAlternatives::class)->handle($request, $s['jefe']);
})->with(['sin choques', 'solo texto'])->throws(ValidationException::class);

it('CR-03: elegir una alternativa aprueba la solicitud, pasa la carga a ajustes y la deja registrada', function () {
    $s = alternativesScenario();
    $request = app(ProposeChangeAlternatives::class)->handle(alternativesRequest($s), $s['jefe']);
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
    $request = app(ProposeChangeAlternatives::class)->handle(alternativesRequest($s), $s['jefe']);
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
    $request = app(ProposeChangeAlternatives::class)->handle(alternativesRequest($s), $s['jefe']);

    app(ChooseChangeAlternative::class)->handle($request, $s['jefe'], 'no-existe');
})->throws(ValidationException::class);

it('alcance: el jefe de otro departamento, el coordinador y el docente no piden ni eligen alternativas', function (string $who) {
    $s = alternativesScenario();
    $request = app(ProposeChangeAlternatives::class)->handle(alternativesRequest($s), $s['jefe']);
    $actor = match ($who) {
        'otro jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create(),
        'coordinador' => $s['coordinador'],
        'docente' => $s['teacherUser'],
    };

    expect(fn () => app(ProposeChangeAlternatives::class)->handle($request, $actor))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChooseChangeAlternative::class)->handle($request, $actor, $request->alternatives['items'][0]['id']))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);
})->with(['otro jefe', 'coordinador', 'docente']);
