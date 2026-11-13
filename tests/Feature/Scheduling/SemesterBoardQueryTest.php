<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\SemesterBoardQuery;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Facades\DB;

/*
| SemesterBoardQuery (tarea 3.5): lo que el editor pinta de un semestre reticular de una carrera en un periodo y
| campus. Los grupos de la carrera en ese plan y semestre, más los de otra carrera donde esta tiene cupo y cuya materia
| está en ese plan y semestre (compartidos). Por grupo, su materia, docente, cupos, sesiones, horas y lo que el actor
| puede hacer según GroupPolicy, solo con la carga de su departamento en propuesta, asignación o ajustes (si no, por
| qué); y las violaciones del validador por grupo y por sesión.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create(['name' => 'Campus II']);
    $this->sistemas = Department::factory()->create(['code' => 'DSC', 'name' => 'Sistemas y Computación']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
        'term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $this->sistemas->id,
    ]);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->sistemas->id]);
    $this->iind = Career::factory()->create(['code' => 'IIND']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->campus->id]);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->isc->id]);
    $this->iindPlan = StudyPlan::factory()->create(['career_id' => $this->iind->id]);
});

function boardSubject(array $attributes = [], int $semester = 1): Subject
{
    $subject = Subject::factory()->create($attributes);
    placeInPlan(test()->plan, $subject, $semester);

    return $subject;
}

function semesterBoardGroup(Subject $subject, array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => test()->campus->id,
        'subject_id' => $subject->id,
        'owner_career_id' => test()->isc->id,
        'study_plan_id' => test()->plan->id,
        'semester' => 1,
        ...$attributes,
    ]);
}

function boardRoom(array $attributes = []): Room
{
    return Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => test()->campus->id])->id, ...$attributes]);
}

/**
 * @return array{groups: list<array<string, mixed>>, violations: array{groups: array<string, list<Violation>>, sessions: array<string, list<Violation>>}}
 */
function semesterBoardFor(User $user, int $semester = 1): array
{
    test()->actingAs($user);

    return app(SemesterBoardQuery::class)->for(test()->term, test()->campus, modalityOf('PRES'), test()->isc, test()->plan, $semester, app(Actor::class));
}

it('trae los grupos de la carrera en el plan y semestre y los compartidos donde la carrera tiene cupo, en el orden de la retícula', function () {
    $second = boardSubject(['code' => 'AAA-0001']);
    $first = boardSubject(['code' => 'ZZZ-0001']);
    DB::table('study_plan_subjects')->where('subject_id', $first->id)->update(['position' => 1]);
    DB::table('study_plan_subjects')->where('subject_id', $second->id)->update(['position' => 2]);
    $own = semesterBoardGroup($first, ['code' => 'A']);
    $ownB = semesterBoardGroup($second, ['code' => 'B']);
    $sharedSubject = boardSubject(['code' => 'MMM-0001']);
    $shared = semesterBoardGroup($sharedSubject, ['code' => 'S', 'owner_career_id' => $this->iind->id, 'study_plan_id' => $this->iindPlan->id, 'semester' => 4]);
    GroupQuota::factory()->create(['group_id' => $shared->id, 'career_id' => $this->isc->id]);

    semesterBoardGroup($first, ['semester' => 2]);
    semesterBoardGroup($first, ['status' => GroupStatus::Cancelled]);
    semesterBoardGroup($first, ['campus_id' => Campus::factory()->create()->id]);
    semesterBoardGroup($first, ['term_id' => Term::factory()->create()->id]);
    semesterBoardGroup($first, ['study_plan_id' => StudyPlan::factory()->create(['career_id' => $this->isc->id])->id]);
    semesterBoardGroup($sharedSubject, ['owner_career_id' => $this->iind->id, 'study_plan_id' => $this->iindPlan->id]);
    $otherSemester = semesterBoardGroup(boardSubject(semester: 2), ['owner_career_id' => $this->iind->id, 'study_plan_id' => $this->iindPlan->id]);
    GroupQuota::factory()->create(['group_id' => $otherSemester->id, 'career_id' => $this->isc->id]);

    $board = semesterBoardFor(User::factory()->admin()->create());

    expect(array_column($board['groups'], 'id'))->toBe([$own->id, $ownB->id, $shared->id])
        ->and(array_column($board['groups'], 'shared'))->toBe([false, false, true]);
});

it('M1: solo trae los grupos de la modalidad de la página, y campusCounts cuenta los del semestre en cada campus', function () {
    modalityOf('EAD')->update(['is_active' => true]);
    $subject = boardSubject();
    $own = semesterBoardGroup($subject, ['code' => 'A']);
    semesterBoardGroup($subject, ['code' => 'E', 'modality_id' => modalityOf('EAD')->id]);
    $elsewhere = Campus::factory()->create(['code' => 'C1']);
    semesterBoardGroup($subject, ['code' => 'Z', 'campus_id' => $elsewhere->id]);

    expect(array_column(semesterBoardFor(User::factory()->admin()->create())['groups'], 'id'))->toBe([$own->id])
        ->and(app(SemesterBoardQuery::class)->campusCounts($this->term, modalityOf('PRES'), $this->isc, $this->plan, 1))
        ->toEqualCanonicalizing([$this->campus->code => 1, 'C1' => 1])
        ->and(app(SemesterBoardQuery::class)->campusCounts($this->term, modalityOf('EAD'), $this->isc, $this->plan, 1))->toBe([$this->campus->code => 1]);
});

it('por grupo da la materia con su departamento, el docente, la capacidad, los cupos, las sesiones con su salón y las horas', function () {
    $subject = boardSubject(['code' => 'SCD-1001', 'name' => 'Cálculo Diferencial', 'theory_hours' => 3, 'practice_hours' => 2, 'needs_lab' => true, 'department_id' => $this->sistemas->id]);
    $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $group = semesterBoardGroup($subject, ['code' => 'D', 'capacity' => 30, 'teacher_id' => $teacher->id]);
    GroupQuota::factory()->create(['group_id' => $group->id, 'career_id' => $this->isc->id, 'max_seats' => 20, 'is_owner' => true]);
    GroupQuota::factory()->create(['group_id' => $group->id, 'career_id' => $this->iind->id, 'max_seats' => 10]);
    $lab = boardRoom(['code' => 'LC-1', 'department_id' => $this->sistemas->id]);
    $late = GroupSession::factory()->for($group)->practica()->inRoom($lab)->on(3, '09:00', '11:00')->create();
    $early = GroupSession::factory()->for($group)->on(1, '07:00', '08:30')->create();

    [$row] = semesterBoardFor(User::factory()->admin()->create())['groups'];

    expect($row)->toBe([
        'id' => $group->id,
        'code' => 'D',
        'modality_id' => $group->modality_id,
        'campus' => ['id' => $this->campus->id, 'code' => $this->campus->code, 'name' => $this->campus->name],
        'status' => GroupStatus::Planned,
        'shared' => false,
        'context' => false,
        'subject' => ['id' => $subject->id, 'code' => 'SCD-1001', 'name' => 'Cálculo Diferencial', 'theory_hours' => 3, 'practice_hours' => 2, 'needs_lab' => true, 'department_code' => 'DSC', 'department_name' => 'Sistemas y Computación'],
        'teacher_id' => $teacher->id,
        'teacher' => 'Ana Pérez',
        'capacity' => 30,
        'quotas' => [
            ['career_id' => $this->isc->id, 'career_code' => 'ISC', 'seats' => 20, 'owner' => true],
            ['career_id' => $this->iind->id, 'career_code' => 'IIND', 'seats' => 10, 'owner' => false],
        ],
        'sessions' => [
            ['id' => $early->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '08:30', 'kind' => SessionKind::Teoria, 'room' => null],
            ['id' => $late->id, 'day' => 3, 'starts_at' => '09:00', 'ends_at' => '11:00', 'kind' => SessionKind::Practica,
                'room' => ['id' => $lab->id, 'code' => 'LC-1', 'department_id' => $this->sistemas->id, 'department_code' => 'DSC']],
        ],
        'required_hours' => 5,
        'placed_hours' => 3.5,
        'can' => ['editSessions' => true, 'assignRoom' => true, 'manageQuotas' => true],
        'edit_campuses' => [],
        'editBlockedReason' => null,
    ]);
});

it('con la carga que no está en propuesta, asignación o ajustes nadie acomoda, asigna salón ni reparte cupos, y el grupo dice por qué', function () {
    $closed = semesterBoardGroup(boardSubject(['department_id' => $this->sistemas->id]));
    $basicas = Department::factory()->create(['name' => 'Ciencias Básicas']);
    $withoutLoad = semesterBoardGroup(boardSubject(['department_id' => $basicas->id]));
    $this->load->update(['status' => LoadStatus::Closed]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sistemas->id])->create();

    $rows = collect(semesterBoardFor($jefe)['groups'])->keyBy('id');

    expect($rows[$closed->id]['can'])->toBe(['editSessions' => false, 'assignRoom' => false, 'manageQuotas' => false])
        ->and($rows[$closed->id]['editBlockedReason'])->toBe('La carga de Sistemas y Computación en Presencial está en cerrada: no se editan horarios.')
        ->and($rows[$withoutLoad->id]['editBlockedReason'])->toBe('Ciencias Básicas no tiene carga en Presencial en este periodo: no se editan horarios.');
});

it('dice lo que puede hacer cada quien según GroupPolicy: el compartido es de solo lectura para el coordinador', function () {
    $own = semesterBoardGroup(boardSubject(['department_id' => $this->sistemas->id]));
    $shared = semesterBoardGroup(boardSubject(['department_id' => $this->sistemas->id]), ['owner_career_id' => $this->iind->id, 'study_plan_id' => $this->iindPlan->id]);
    GroupQuota::factory()->create(['group_id' => $shared->id, 'career_id' => $this->isc->id]);
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sistemas->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    $can = fn (User $user): array => array_column(semesterBoardFor($user)['groups'], 'can', 'id');

    expect($can($coordinator))->toEqual([
        $own->id => ['editSessions' => true, 'assignRoom' => false, 'manageQuotas' => false],
        $shared->id => ['editSessions' => false, 'assignRoom' => false, 'manageQuotas' => false],
    ])
        ->and($can($jefe)[$shared->id])->toBe(['editSessions' => true, 'assignRoom' => true, 'manageQuotas' => true])
        ->and($can($subdireccion)[$own->id])->toBe(['editSessions' => false, 'assignRoom' => false, 'manageQuotas' => false]);
});

it('las banderas salen del puesto activo del actor que recibe, no de todos los puestos de su usuario', function () {
    semesterBoardGroup(boardSubject(['department_id' => $this->sistemas->id]));
    $both = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => $this->sistemas->id])
        ->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
    actingAsRole($both, Role::JefeDepartamento);

    $asCoordinator = app(SemesterBoardQuery::class)->for($this->term, $this->campus, modalityOf('PRES'), $this->isc, $this->plan, 1, app(Actor::class)->asRole(Role::Coordinador));
    $asJefe = app(SemesterBoardQuery::class)->for($this->term, $this->campus, modalityOf('PRES'), $this->isc, $this->plan, 1, app(Actor::class));

    expect($asCoordinator['groups'][0]['can'])->toBe(['editSessions' => true, 'assignRoom' => false, 'manageQuotas' => false])
        ->and($asJefe['groups'][0]['can'])->toBe(['editSessions' => true, 'assignRoom' => true, 'manageQuotas' => true]);
});

it('quien ve la carrera ve todos los grupos de su semestre: los que no ve por GroupPolicy van como contexto de solo lectura', function () {
    $own = semesterBoardGroup(boardSubject(['department_id' => $this->sistemas->id]));
    $basicas = Department::factory()->create();
    AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $basicas->id]);
    $calculo = semesterBoardGroup(boardSubject(['department_id' => $basicas->id]));
    $shared = semesterBoardGroup(boardSubject(['department_id' => $this->sistemas->id]), ['owner_career_id' => $this->iind->id, 'study_plan_id' => $this->iindPlan->id]);
    GroupQuota::factory()->create(['group_id' => $shared->id, 'career_id' => $this->isc->id]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sistemas->id])->create();
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();

    $context = fn (User $user): array => array_column(semesterBoardFor($user)['groups'], 'context', 'id');
    $jefeRows = collect(semesterBoardFor($jefe)['groups'])->keyBy('id');

    expect($context($jefe))->toEqual([$own->id => false, $calculo->id => true, $shared->id => false])
        ->and($jefeRows[$calculo->id]['can'])->toBe(['editSessions' => false, 'assignRoom' => false, 'manageQuotas' => false])
        ->and($context($coordinator))->toEqual([$own->id => false, $calculo->id => false, $shared->id => true]);
});

it('junta las violaciones del validador por grupo y por sesión', function () {
    $room = boardRoom(['code' => 'A-1', 'capacity' => 40]);
    $group = semesterBoardGroup(boardSubject(['theory_hours' => 2, 'practice_hours' => 0]));
    $clean = semesterBoardGroup(boardSubject());
    $session = GroupSession::factory()->for($group)->inRoom($room)->on(2, '07:00', '09:00')->create();
    $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $taken = GroupSession::factory()->for($other)->on(2, '08:00', '10:00')->create();
    DB::statement('set constraints group_sessions_room_no_overlap deferred');
    DB::table('group_sessions')->where('id', $taken->id)->update(['room_id' => $room->id]);

    $violations = semesterBoardFor(User::factory()->admin()->create())['violations'];

    expect(array_keys($violations['groups']))->toBe([$group->id])
        ->and(array_map(fn (Violation $violation): string => $violation->rule, $violations['groups'][$group->id]))->toBe(['HC-02'])
        ->and(array_keys($violations['sessions']))->toBe([$session->id])
        ->and($violations['sessions'][$session->id][0]->message)->toStartWith('El salón A-1 ya está ocupado')
        ->and($violations['groups'])->not->toHaveKey($clean->id);

    foreach ([$group, $clean] as $each) {
        expect($violations['groups'][$each->id] ?? [])->toEqual(app(ScheduleValidator::class)->validateGroup($each)->violations());
    }
});

it('lee con pocas consultas aunque el semestre tenga 10 grupos', function () {
    $room = boardRoom();

    foreach (range(1, 10) as $index) {
        $group = semesterBoardGroup(boardSubject(), ['teacher_id' => Teacher::factory()->create()->id]);
        GroupQuota::factory()->create(['group_id' => $group->id, 'career_id' => $this->iind->id]);
        GroupSession::factory()->for($group)->inRoom($room)->on(1 + $index % 5, sprintf('%02d:00', 7 + $index), sprintf('%02d:00', 8 + $index))->create();
        GroupSession::factory()->for($group)->on(1 + $index % 5, sprintf('%02d:00', 18), sprintf('%02d:00', 19))->create(['day' => 1 + ($index + 2) % 5]);
    }

    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
    $this->actingAs($user);
    app(Actor::class)->allowsAny(SchedulingPermission::ScheduleView);

    DB::enableQueryLog();
    $board = app(SemesterBoardQuery::class)->for($this->term, $this->campus, modalityOf('PRES'), $this->isc, $this->plan, 1, app(Actor::class));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($board['groups'])->toHaveCount(10)
        ->and($queries)->toBeLessThanOrEqual(30);
});
