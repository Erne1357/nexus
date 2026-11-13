<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Facades\DB;

/*
| ScheduleValidator contra la base (tarea 3.2): valida un grupo, un docente, un salón o una carga, y pregunta sin
| escribir por una sesión candidata (editor) o por otro docente (ranking). La foto es por periodo y modalidad (M1): la
| carga presencial junta los grupos de C1 y C2, y cada regla lee el perfil de la modalidad del grupo.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->modality = Modality::query()->where('code', 'PRES')->sole();
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
        ->and((new ValidationResult($result->forRule('HC-02')))->messages())->toBe(['El salón LC-1 ya está ocupado por Programación, grupo H el martes de 8:00 a 10:00.']);
});

it('checkSession dice qué pasaría con una sesión candidata, sin escribir', function () {
    $group = validatorGroup(['teacher_id' => $this->teacher->id]);
    $busy = validatorGroup(['teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($busy)->on(3, '09:00', '11:00')->create();

    $clash = $this->validator->checkSession($group, new SessionCandidate(3, '10:00', '12:00'));
    $free = $this->validator->checkSession($group, new SessionCandidate(3, '11:00', '12:00'));
    $batch = $this->validator->checkSessions($group, ['a' => new SessionCandidate(3, '09:00', '10:00'), 'b' => new SessionCandidate(4, '09:00', '10:00')]);

    expect($clash->forRule('HC-01'))->toHaveCount(1)
        ->and($free->forRule('HC-01'))->toBe([])
        ->and($batch['a']->forRule('HC-01'))->toHaveCount(1)
        ->and($batch['b']->forRule('HC-01'))->toBe([])
        ->and($group->sessions()->count())->toBe(0);
});

it('checkTeacher responde el choque del candidato, aunque la foto no lo traiga, y no se cuenta a sí mismo', function () {
    $group = validatorGroup();
    GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
    $busy = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($busy)->on(1, '08:00', '09:00')->create();
    $free = Teacher::factory()->create();

    $results = $this->validator->checkTeachers($group, [$this->teacher, $free]);

    expect(rulesOf($results[$this->teacher->id]->violations()))->toContain('HC-01')
        ->and($results[$this->teacher->id]->forRule('HC-01'))->toHaveCount(1)
        ->and($results[$this->teacher->id]->violations()[0]->groupId)->toBe($group->id)
        ->and($results[$free->id]->forRule('HC-01'))->toBe([]);

    $group->update(['teacher_id' => $free->id]);
    $this->validator->forget();

    expect($this->validator->checkTeacher($group, $free)->forRule('HC-01'))->toBe([]);
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

it('valida un salón y toda la carga de una modalidad o de un departamento en ella', function () {
    $group = validatorGroup();
    $other = validatorGroup();
    GroupSession::factory()->for($group)->inRoom($this->room)->on(2, '07:00', '09:00')->create();
    $session = GroupSession::factory()->for($other)->on(2, '07:00', '08:00')->create();
    DB::statement('set constraints group_sessions_room_no_overlap deferred');
    DB::table('group_sessions')->where('id', $session->id)->update(['room_id' => $this->room->id]);

    expect($this->validator->validateRoom($this->room, $this->term)->forRule('HC-02'))->toHaveCount(2)
        ->and($this->validator->validateLoad($this->term, $this->modality)->forRule('HC-02'))->toHaveCount(2)
        ->and((new ValidationResult($this->validator->validateLoad($this->term, $this->modality, $group->department)->forGroup($group->id)))->forRule('HC-02'))->toHaveCount(1);
});

it('lee la base una vez por periodo y modalidad hasta que se le pide olvidar', function () {
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

it('checkTeacher avisa del traslado justo entre campus (SC-09) como regla blanda', function () {
    $group = validatorGroup();
    GroupSession::factory()->for($group)->on(3, '09:30', '11:00')->create();
    $elsewhere = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($elsewhere)->on(3, '07:00', '09:00')->create();

    $result = $this->validator->checkTeacher($group, $this->teacher);

    expect($result->forRule('SC-09'))->toHaveCount(1)
        ->and($result->forRule('SC-09')[0]->related)->toMatchArray(['free_minutes' => 30, 'other_group_id' => $elsewhere->id])
        ->and($result->forRule('SC-09')[0]->severity)->toBe(ConflictSeverity::Soft)
        ->and($result->forRule('SC-09')[0]->message)->toContain("a {$this->campus->code} (9:30)");
});

it('teacherHours da a cada candidato sus horas sin el grupo y con él', function () {
    $group = validatorGroup(['subject_id' => Subject::factory()->create(['theory_hours' => 3, 'practice_hours' => 2])->id]);
    $other = Teacher::factory()->create();

    $hours = $this->validator->teacherHours($group, [$this->teacher, $other]);

    expect($hours[$this->teacher->id]['before']['assigned_hours'])->toBe(0)
        ->and($hours[$this->teacher->id]['after']['assigned_hours'])->toBe(5)
        ->and($hours[$this->teacher->id]['after']['remaining_hours'])->toBe($hours[$this->teacher->id]['before']['remaining_hours'] - 5)
        ->and($hours)->toHaveKey($other->id);
});

it('la carga de una modalidad junta los grupos de todos sus campus en una sola foto (M1)', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $here = validatorGroup();
    $there = validatorGroup(['campus_id' => $c1->id]);
    $ead = validatorGroup(['modality_id' => Modality::query()->where('code', 'EAD')->value('id')]);

    $state = $this->validator->stateFor($this->term->id, $this->modality->id);
    $load = $this->validator->validateLoad($this->term, $this->modality);

    expect($state->group($here->id))->not->toBeNull()
        ->and($state->group($there->id))->not->toBeNull()
        ->and($state->group($ead->id))->toBeNull()
        ->and($load->forGroup($ead->id))->toBe([]);
});

it('SC-09 avisa del traslado de C1 a C2 dentro de la misma carga presencial', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $morning = validatorGroup(['campus_id' => $c1->id, 'teacher_id' => $this->teacher->id]);
    $noon = validatorGroup(['teacher_id' => $this->teacher->id]);
    GroupSession::factory()->for($morning)->on(2, '07:00', '09:00')->create();
    GroupSession::factory()->for($noon)->on(2, '09:30', '11:00')->create();

    $travel = $this->validator->validateLoad($this->term, $this->modality)->forRule('SC-09');

    expect(array_map(fn (Violation $violation): ?string => $violation->groupId, $travel))->toEqualCanonicalizing([$morning->id, $noon->id])
        ->and($travel[0]->severity)->toBe(ConflictSeverity::Soft)
        ->and($travel[0]->message)->toContain("de C1 (9:00) a {$this->campus->code} (9:30)");
});

it('HC-03 se revisa por campus dentro de la carga presencial (MS10): el grupo de C1 no le sirve al alumno tipo de C2', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $calculus = Subject::factory()->create();
    $chemistry = Subject::factory()->create();
    $plan = StudyPlan::factory()->create();
    $shared = ['owner_career_id' => $plan->career_id, 'study_plan_id' => $plan->id, 'semester' => 1];
    $onlyHere = validatorGroup(['subject_id' => $calculus->id, ...$shared]);
    $alternative = validatorGroup(['subject_id' => $calculus->id, 'campus_id' => $c1->id, ...$shared]);
    $clash = validatorGroup(['subject_id' => $chemistry->id, ...$shared]);
    GroupSession::factory()->for($onlyHere)->on(1, '08:00', '10:00')->create();
    GroupSession::factory()->for($alternative)->on(2, '08:00', '10:00')->create();
    GroupSession::factory()->for($clash)->on(1, '09:00', '11:00')->create();

    $typical = $this->validator->validateLoad($this->term, $this->modality)->forRule('HC-03');

    expect(array_map(fn (Violation $violation): ?string => $violation->groupId, $typical))->toEqualCanonicalizing([$onlyHere->id, $clash->id]);
});

it('HC-04 usa la ventana de la modalidad: con sábado en su perfil, una sesión en sábado no es choque aunque el perfil del campus no lo tenga', function () {
    $this->campus->update(['settings' => ['days' => [1, 2, 3, 4, 5]]]);
    $this->modality->update(['settings' => [...ModalityProfile::DEFAULTS, 'days' => [1, 2, 3, 4, 5, 6]]]);
    $group = validatorGroup();
    GroupSession::factory()->for($group)->on(6, '08:00', '10:00')->create();

    expect($this->validator->validateGroup($group)->forRule('HC-04'))->toBe([]);

    $this->modality->update(['settings' => ModalityProfile::DEFAULTS]);
    $this->validator->forget();

    expect($this->validator->validateGroup($group)->forRule('HC-04'))->toHaveCount(1);
});

it('HC-12 usa el perfil de la modalidad del grupo: su tolerancia y sus bloques de tiempo (MS17)', function () {
    $group = validatorGroup(['subject_id' => Subject::factory()->create(['theory_hours' => 3, 'practice_hours' => 2])->id]);
    GroupSession::factory()->for($group)->on(1, '07:00', '11:00')->create();

    expect($this->validator->validateGroup($group)->forRule('HC-12'))->toHaveCount(1);

    $this->modality->update(['settings' => [...ModalityProfile::DEFAULTS, 'weekly_hours_tolerance' => 60]]);
    $this->validator->forget();

    expect($this->validator->validateGroup($group)->forRule('HC-12'))->toBe([]);
});
