<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Queries\ConflictBoardQuery;
use App\Modules\Scheduling\Queries\GroupBoardStatus;

/*
| ConflictBoardQuery (tarea 3.4): qué le falta a cada grupo para cerrar (HC-15), contado por carrera, retícula y
| semestre, y por departamento, solo en el alcance de quien mira. Es de la carga de una modalidad (M1), con el campus
| como filtro opcional.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->modality = modalityOf('PRES');
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

function boardStatuses(User $user, ?Campus $campus = null)
{
    return app(ConflictBoardQuery::class)->statuses(app(Actor::class)->forUser($user), test()->term, test()->modality, $campus);
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

it('con una modalidad sin bloques de tiempo no cuenta grupos sin horario y sin salones no cuenta grupos sin salón', function () {
    $this->modality->update(['settings' => [...ModalityProfile::DEFAULTS, 'uses_time_blocks' => false, 'uses_rooms' => false]]);
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

it('el detalle trae los choques agrupados por grupo y los grupos de cada pendiente', function () {
    $clash = readyBoardGroup(['code' => 'D']);
    ScheduleConflict::factory()->forGroup($clash)->create(['rule' => 'HC-03', 'message' => 'Se empalma con Química.']);
    ScheduleConflict::factory()->forGroup($clash)->create(['rule' => 'HC-02']);
    boardGroup(['code' => '2']);

    $detail = app(ConflictBoardQuery::class)->detail(boardStatuses(User::factory()->admin()->create()));

    expect($detail['conflicts'])->toHaveCount(1)
        ->and($detail['conflicts'][0]['items'])->toHaveCount(2)
        ->and($detail['conflicts'][0]['items'][0])->toMatchArray(['rule' => 'HC-02'])
        ->and($detail['conflicts'][0]['items'][1])->toMatchArray(['rule' => 'HC-03', 'message' => 'Se empalma con Química.'])
        ->and($detail['conflicts'][0]['group'])->toMatchArray(['code' => 'D', 'subject_code' => $this->subject->code, 'career_code' => 'ISC'])
        ->and(array_column($detail['unscheduled'], 'code'))->toBe(['2'])
        ->and(array_column($detail['withoutTeacher'], 'code'))->toBe(['2']);
});

it('cada quien cuenta solo su alcance: el jefe su departamento, el coordinador su carrera en la modalidad (AZ-S17)', function () {
    readyBoardGroup();
    boardGroup(['subject_id' => Subject::factory()->create(['department_id' => departmentKey(11)])->id]);
    $otherCareer = Career::factory()->create();
    boardGroup(['owner_career_id' => $otherCareer->id, 'study_plan_id' => StudyPlan::factory()->create(['career_id' => $otherCareer->id])->id]);

    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
    $otherCampus = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id])->create();

    expect(boardStatuses($jefe))->toHaveCount(1)
        ->and(boardStatuses($coordinador))->toHaveCount(2)
        ->and(boardStatuses($otherCampus))->toHaveCount(2)
        ->and(boardStatuses(User::factory()->withRole(Role::Docente)->create()))->toHaveCount(0);
});

/**
 * Un grupo de IIND con cupo de ISC: la materia está en el semestre 3 del plan vigente de ISC y no está en la retícula
 * vieja.
 */
function sharedBoardGroup(): Group
{
    $iind = Career::factory()->create(['code' => 'IIND']);
    $group = boardGroup([
        'owner_career_id' => $iind->id,
        'study_plan_id' => StudyPlan::factory()->create(['career_id' => $iind->id, 'semesters_count' => 9])->id,
        'semester' => 2,
    ]);
    StudyPlanSubject::factory()->create(['study_plan_id' => test()->plan->id, 'subject_id' => test()->subject->id, 'semester' => 3]);
    GroupQuota::factory()->create(['group_id' => $group->id, 'career_id' => test()->career->id, 'max_seats' => 10]);

    return $group;
}

it('un grupo compartido cuenta en cada carrera con cupo, en la retícula y el semestre de su materia en ese plan', function () {
    $shared = sharedBoardGroup();
    $board = app(ConflictBoardQuery::class);
    $statuses = boardStatuses(User::factory()->admin()->create());
    $grid = $board->grid($statuses, $this->career->id);

    expect($board->summary($statuses)->groups)->toBe(1)
        ->and(array_map(fn ($row) => [$row['code'], $row['count']->groups], $board->byCareer($statuses)))->toBe([['IIND', 1], ['ISC', 1]])
        ->and(array_column($grid['plans'], 'id'))->toBe([$this->plan->id])
        ->and($grid['plans'][0]['cells'][3])->groups->toBe(1)->missing->toBe(1)
        ->and($statuses->sole()->placedIn($this->career->id, $this->plan->id, 3))->toBeTrue()
        ->and($statuses->sole()->placedIn($shared->owner_career_id, $shared->study_plan_id, 2))->toBeTrue()
        ->and($statuses->filter(fn (GroupBoardStatus $status): bool => $status->countsFor($this->career->id)))->toHaveCount(1);
});

it('el coordinador ve los grupos de otra carrera con cupo de la suya, y no los que no le dan cupo', function () {
    sharedBoardGroup();
    $iind = Career::query()->where('code', 'IIND')->sole();
    boardGroup(['owner_career_id' => $iind->id, 'study_plan_id' => StudyPlan::factory()->create(['career_id' => $iind->id])->id]);
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();

    expect(boardStatuses($coordinador))->toHaveCount(1)
        ->and(boardStatuses(User::factory()->admin()->create()))->toHaveCount(2);
});

it('dice cuándo se recalcularon los choques de la modalidad, aunque el recálculo no haya cambiado nada', function () {
    $board = app(ConflictBoardQuery::class);

    expect($board->lastSyncedAt($this->term, $this->modality))->toBeNull();

    app(SyncScheduleConflicts::class)->handle($this->term, $this->modality);

    expect($board->lastSyncedAt($this->term, $this->modality))->not->toBeNull()
        ->and($board->hasDepartment(app(Actor::class)->forUser(User::factory()->admin()->create()), $this->term, $this->modality, null, departmentKey(10)))->toBeFalse();
});

it('es de la carga de una modalidad con todos sus campus (M1) y el campus es un filtro; otra modalidad no cuenta', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $here = boardGroup();
    $there = boardGroup(['campus_id' => $c1->id]);
    boardGroup(['modality_id' => modalityOf('EAD')->id]);
    $admin = User::factory()->admin()->create();
    $board = app(ConflictBoardQuery::class);
    $actor = app(Actor::class)->forUser($admin);

    expect(boardStatuses($admin)->pluck('id')->all())->toEqualCanonicalizing([$here->id, $there->id])
        ->and(boardStatuses($admin, $c1)->pluck('id')->all())->toBe([$there->id])
        ->and($board->hasDepartment($actor, $this->term, $this->modality, $c1, departmentKey(10)))->toBeTrue()
        ->and($board->hasDepartment($actor, $this->term, $this->modality, Campus::factory()->create(), departmentKey(10)))->toBeFalse();
});

it('HC-15: un grupo con choque duro no está listo para cerrar, aunque tenga horario, docente y salón', function () {
    $clash = readyBoardGroup();
    ScheduleConflict::factory()->forGroup($clash)->create();

    $summary = app(ConflictBoardQuery::class)->summary(boardStatuses(User::factory()->admin()->create()));

    expect($summary)->hard->toBe(1)->missing->toBe(1)
        ->and($summary->ready())->toBe(0);
});

it('P10: un docente dado de baja deja al grupo sin docente y el detalle lo marca', function () {
    readyBoardGroup();
    $inactive = readyBoardGroup(['teacher_id' => Teacher::factory()->inactive()->create()->id]);
    $deleted = Teacher::factory()->create();
    readyBoardGroup(['teacher_id' => $deleted->id]);
    $deleted->delete();
    $unassigned = boardGroup();
    GroupSession::factory()->for($unassigned)->inRoom($this->room)->on(5, '11:00', '13:00')->create();

    $query = app(ConflictBoardQuery::class);
    $statuses = boardStatuses(User::factory()->admin()->create());

    expect($query->summary($statuses)->withoutTeacher)->toBe(3);

    $detail = $query->detail($statuses)['withoutTeacher'];
    expect(collect($detail)->firstWhere('id', $inactive->id)['inactive_teacher'])->toBeTrue()
        ->and(collect($detail)->firstWhere('id', $unassigned->id)['inactive_teacher'])->toBeFalse()
        ->and(collect($detail)->where('inactive_teacher', true)->count())->toBe(2);
});
