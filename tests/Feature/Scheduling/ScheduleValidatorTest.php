<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Facades\DB;

/*
| ScheduleValidator contra la base (tarea 3.2): valida un grupo, un docente, un salón o una carga, y pregunta sin
| escribir por una sesión candidata (editor) o por otro docente (ranking).
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id, 'code' => 'LC-1']);
    $this->teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $this->validator = app(ScheduleValidator::class);
});

function validatorGroup(array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, ...$attributes]);
}

/**
 * @param  list<Violation>  $violations
 * @return list<string>
 */
function rulesOf(array $violations): array
{
    return array_map(fn (Violation $violation): string => $violation->rule, $violations);
}

it('valida un grupo con sus empalmes de salón', function () {
    $group = validatorGroup();
    $other = validatorGroup(['code' => 'H', 'subject_id' => Subject::factory()->create(['name' => 'Programación'])->id]);
    GroupSession::factory()->for($group)->inRoom($this->room)->on(2, '07:00', '09:00')->create();
    $session = GroupSession::factory()->for($other)->on(2, '08:00', '10:00')->create();
    DB::statement('set constraints group_sessions_room_no_overlap deferred');
    DB::table('group_sessions')->where('id', $session->id)->update(['room_id' => $this->room->id]);

    $result = $this->validator->validateGroup($group);

    expect($result->hasHard())->toBeTrue()
        ->and($result->messages())->toBe(['El salón LC-1 ya está ocupado por Programación, grupo H el martes de 08:00 a 10:00.']);
});

it('checkSession dice qué pasaría con una sesión candidata, sin escribir', function () {
    $group = validatorGroup(['teacher_id' => $this->teacher->id]);
    $busy = validatorGroup(['teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($busy)->on(3, '09:00', '11:00')->create();

    $clash = $this->validator->checkSession($group, new SessionCandidate(3, '10:00', '12:00'));
    $free = $this->validator->checkSession($group, new SessionCandidate(3, '11:00', '12:00'));
    $batch = $this->validator->checkSessions($group, ['a' => new SessionCandidate(3, '09:00', '10:00'), 'b' => new SessionCandidate(4, '09:00', '10:00')]);

    expect(rulesOf($clash->violations()))->toBe(['HC-01'])
        ->and($free->isEmpty())->toBeTrue()
        ->and($batch['a']->hasHard())->toBeTrue()
        ->and($batch['b']->isEmpty())->toBeTrue()
        ->and($group->sessions()->count())->toBe(0);
});

it('checkTeacher responde el choque del candidato, aunque la foto no lo traiga, y no se cuenta a sí mismo', function () {
    $group = validatorGroup();
    GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
    $busy = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($busy)->on(1, '08:00', '09:00')->create();
    $free = Teacher::factory()->create();

    $results = $this->validator->checkTeachers($group, [$this->teacher, $free]);

    expect(rulesOf($results[$this->teacher->id]->violations()))->toBe(['HC-01'])
        ->and($results[$this->teacher->id]->violations()[0]->groupId)->toBe($group->id)
        ->and($results[$free->id]->isEmpty())->toBeTrue();

    $group->update(['teacher_id' => $free->id]);
    $this->validator->forget();

    expect($this->validator->checkTeacher($group, $free)->isEmpty())->toBeTrue();
});

it('valida a un docente en todo el periodo, en cualquier campus', function () {
    $here = validatorGroup(['teacher_id' => $this->teacher->id]);
    $there = Group::factory()->create(['term_id' => $this->term->id]);
    GroupSession::factory()->for($here)->on(5, '07:00', '09:00')->create();
    GroupSession::factory()->for($there)->on(5, '08:00', '09:00')->create();
    DB::statement('set constraints group_sessions_teacher_no_overlap deferred');
    DB::table('groups')->where('id', $there->id)->update(['teacher_id' => $this->teacher->id]);

    expect($this->validator->validateTeacher($this->teacher, $this->term)->forRule('HC-01'))->toHaveCount(2)
        ->and($this->validator->validateTeacher(Teacher::factory()->create(), $this->term)->isEmpty())->toBeTrue();
});

it('valida un salón y toda la carga de un campus o de un departamento', function () {
    $group = validatorGroup();
    $other = validatorGroup();
    GroupSession::factory()->for($group)->inRoom($this->room)->on(2, '07:00', '09:00')->create();
    $session = GroupSession::factory()->for($other)->on(2, '07:00', '08:00')->create();
    DB::statement('set constraints group_sessions_room_no_overlap deferred');
    DB::table('group_sessions')->where('id', $session->id)->update(['room_id' => $this->room->id]);

    expect($this->validator->validateRoom($this->room, $this->term)->violations())->toHaveCount(2)
        ->and($this->validator->validateLoad($this->term, $this->campus)->violations())->toHaveCount(2)
        ->and($this->validator->validateLoad($this->term, $this->campus, $group->department)->forGroup($group->id))->toHaveCount(1);
});

it('lee la base una vez por periodo y campus hasta que se le pide olvidar', function () {
    $group = validatorGroup();
    $this->validator->validateGroup($group);

    DB::enableQueryLog();
    $this->validator->checkSession($group, new SessionCandidate(1, '07:00', '08:00'));
    $cached = count(DB::getQueryLog());

    $this->validator->forget();
    $this->validator->checkSession($group, new SessionCandidate(1, '07:00', '08:00'));

    expect($cached)->toBe(0)
        ->and(count(DB::getQueryLog()))->toBeGreaterThan(0);
});

it('checkTeacher solo corre las reglas del docente', function () {
    $group = validatorGroup();
    GroupSession::factory()->for($group)->inRoom($this->room)->on(1, '07:00', '09:00')->create();
    $other = validatorGroup();
    $session = GroupSession::factory()->for($other)->on(1, '07:00', '09:00')->create();
    DB::statement('set constraints group_sessions_room_no_overlap deferred');
    DB::table('group_sessions')->where('id', $session->id)->update(['room_id' => $this->room->id]);

    expect($this->validator->validateGroup($group)->forRule('HC-02'))->not->toBe([])
        ->and($this->validator->checkTeacher($group, $this->teacher)->forRule('HC-02'))->toBe([]);
});
