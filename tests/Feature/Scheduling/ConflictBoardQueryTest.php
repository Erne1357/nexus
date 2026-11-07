<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Queries\ConflictBoardQuery;

/*
| ConflictBoardQuery (tarea 3.4): qué le falta a cada grupo para cerrar (HC-15), contado por carrera, retícula y
| semestre, y por departamento, solo en el alcance de quien mira.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->career = Career::factory()->create(['code' => 'ISC']);
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'is_current' => true, 'semesters_count' => 9]);
    $this->old = StudyPlan::factory()->create(['career_id' => $this->career->id, 'is_current' => false, 'code' => 'ISIC-2004']);
    $this->subject = Subject::factory()->create(['department_id' => departmentKey(10)]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
});

function boardGroup(array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'subject_id' => test()->subject->id,
        'owner_career_id' => test()->career->id, 'study_plan_id' => test()->plan->id, 'semester' => 1, ...$attributes,
    ]);
}

/** Un grupo listo: docente y una sesión con salón. */
function readyBoardGroup(array $attributes = []): Group
{
    $group = boardGroup(['teacher_id' => Teacher::factory()->create()->id, ...$attributes]);
    GroupSession::factory()->for($group)->inRoom(test()->room)->on(1 + Group::query()->count() % 5, '07:00', '09:00')->create();

    return $group;
}

function boardStatuses(User $user)
{
    return app(ConflictBoardQuery::class)->statuses(app(Actor::class)->forUser($user), test()->term, test()->campus);
}

it('cuenta lo que le falta a cada grupo: choque duro, horario, docente y salón', function () {
    $ready = readyBoardGroup();
    $unscheduled = boardGroup(['teacher_id' => Teacher::factory()->create()->id]);
    $noRoom = boardGroup(['teacher_id' => Teacher::factory()->create()->id]);
    GroupSession::factory()->for($noRoom)->on(3, '07:00', '09:00')->create();
    $virtual = boardGroup(['teacher_id' => Teacher::factory()->create()->id]);
    GroupSession::factory()->for($virtual)->virtual()->on(4, '07:00', '09:00')->create();
    $clash = readyBoardGroup();
    ScheduleConflict::factory()->forGroup($clash)->create();
    ScheduleConflict::factory()->forGroup($ready)->resolved()->create();
    ScheduleConflict::factory()->forGroup($ready)->soft()->create();
    boardGroup(['status' => 'cancelled']);

    $summary = app(ConflictBoardQuery::class)->summary(boardStatuses(User::factory()->admin()->create()));

    expect($summary)
        ->groups->toBe(5)
        ->hard->toBe(1)
        ->unscheduled->toBe(1)
        ->withoutTeacher->toBe(0)
        ->withoutRoom->toBe(1)
        ->missing->toBe(3)
        ->pending->toBe(2)
        ->and($summary->ready())->toBe(2);
});

it('sin bloques de tiempo no cuenta grupos sin horario y sin salones no cuenta grupos sin salón', function () {
    $this->campus->update(['settings' => ['uses_time_blocks' => false, 'uses_rooms' => false]]);
    boardGroup();
    $session = boardGroup();
    GroupSession::factory()->for($session)->on(1, '07:00', '09:00')->create();

    $summary = app(ConflictBoardQuery::class)->summary(boardStatuses(User::factory()->admin()->create()));

    expect($summary)->unscheduled->toBe(0)->withoutRoom->toBe(0)->withoutTeacher->toBe(2);
});

it('la cuadrícula de la carrera pone primero las retículas viejas, con su conteo por semestre', function () {
    readyBoardGroup(['semester' => 3]);
    boardGroup(['semester' => 3]);
    boardGroup(['study_plan_id' => $this->old->id, 'semester' => 7]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);

    $grid = app(ConflictBoardQuery::class)->grid(boardStatuses(User::factory()->admin()->create()), $this->career->id);

    expect($grid['semesters'])->toBe(9)
        ->and(array_column($grid['plans'], 'id'))->toBe([$this->old->id, $this->plan->id])
        ->and($grid['plans'][0]['priority'])->toBeTrue()
        ->and($grid['plans'][1]['cells'][3])->groups->toBe(2)->missing->toBe(1)
        ->and($grid['plans'][1]['cells'])->not->toHaveKey(1);
});

it('cuenta por departamento y por carrera', function () {
    readyBoardGroup();
    boardGroup(['subject_id' => Subject::factory()->create(['department_id' => departmentKey(11)])->id]);

    $board = app(ConflictBoardQuery::class);
    $statuses = boardStatuses(User::factory()->admin()->create());

    expect(array_map(fn ($row) => [$row['id'], $row['count']->groups, $row['count']->missing], $board->byDepartment($statuses)))
        ->toEqualCanonicalizing([[departmentKey(10), 1, 0], [departmentKey(11), 1, 1]])
        ->and($board->byCareer($statuses)[0])->code->toBe('ISC');
});

it('el detalle trae los choques con su grupo y los grupos de cada pendiente', function () {
    $clash = readyBoardGroup(['code' => 'D']);
    ScheduleConflict::factory()->forGroup($clash)->create(['rule' => 'HC-03', 'message' => 'Se empalma con Química.']);
    boardGroup(['code' => '2']);

    $detail = app(ConflictBoardQuery::class)->detail(boardStatuses(User::factory()->admin()->create()));

    expect($detail['conflicts'])->toHaveCount(1)
        ->and($detail['conflicts'][0])->toMatchArray(['rule' => 'HC-03', 'message' => 'Se empalma con Química.'])
        ->and($detail['conflicts'][0]['group'])->toMatchArray(['code' => 'D', 'subject_code' => $this->subject->code, 'career_code' => 'ISC'])
        ->and(array_column($detail['unscheduled'], 'code'))->toBe(['2'])
        ->and(array_column($detail['withoutTeacher'], 'code'))->toBe(['2']);
});

it('cada quien cuenta solo su alcance: el jefe su departamento, el coordinador su carrera en su campus', function () {
    readyBoardGroup();
    boardGroup(['subject_id' => Subject::factory()->create(['department_id' => departmentKey(11)])->id]);
    $otherCareer = Career::factory()->create();
    boardGroup(['owner_career_id' => $otherCareer->id, 'study_plan_id' => StudyPlan::factory()->create(['career_id' => $otherCareer->id])->id]);

    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
    $otherCampus = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();

    expect(boardStatuses($jefe))->toHaveCount(1)
        ->and(boardStatuses($coordinador))->toHaveCount(2)
        ->and(boardStatuses($otherCampus))->toHaveCount(0)
        ->and(boardStatuses(User::factory()->withRole(Role::Docente)->create()))->toHaveCount(0);
});

it('dice cuándo se recalcularon los choques del campus', function () {
    $board = app(ConflictBoardQuery::class);

    expect($board->lastSyncedAt($this->term, $this->campus))->toBeNull();

    ScheduleConflict::factory()->forGroup(boardGroup())->create();

    expect($board->lastSyncedAt($this->term, $this->campus))->not->toBeNull();
});
