<?php

use App\Modules\Academic\Actions\SetSubjectTeacherHours;
use App\Modules\Academic\Events\SubjectUpdated;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Jobs\RecomputeScheduleConflicts;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Actions\Selection\FinishMyLoad;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Jobs\ComputeRankings;
use App\Modules\Staffing\Jobs\RefreshTeacherLoads;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Staffing\SelectionWorld;

/*
| PL-02 (tarea 1.73): las horas de una materia que no cuenta en la carga del docente (las tutorías) le cuentan 0 h en
| todo cálculo de sus horas: HC-08, la banda de la orden de selección y la carga por docente. El grupo sigue ocupando su
| horario (HC-01 sigue aplicando) y sus horas de sesión siguen siendo las de la materia.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create();
    $this->teacher = $this->world->teacher(base: 5);
    $this->group = $this->world->group('A', [[1, '09:00', '10:00']], theory: 3, practice: 2);
    $this->tutoring = $this->world->group('T', [[2, '09:00', '10:00']], theory: 1, practice: 0, attributes: ['teacher_id' => $this->teacher->id]);
    $this->tutoring->subject->update(['counts_teacher_hours' => false]);
    app(PickSelectionGroup::class)->handle($this->world->selection, $this->teacher, $this->group, $this->world->room('A1'), $this->teacher->user);
});

it('PL-02 y HC-08: la tutoría le cuenta 0 h al docente y sus horas de sesión siguen siendo las de la materia', function () {
    $validator = app(ScheduleValidator::class);
    $validator->forget();
    $hours = $validator->teacherTermHours($this->teacher, $this->world->term);

    expect($hours['assigned_hours'])->toBe(5)
        ->and($hours['groups'])->toHaveCount(2)
        ->and($hours['groups'][$this->group->id])->toBe(5)
        ->and($hours['groups'][$this->tutoring->id])->toBe(0)
        ->and($validator->validateGroup($this->tutoring)->forRule('HC-12'))->toBe([]);
});

it('PL-02 y HC-08: sin la marca la tutoría sí contaría y el docente pasaría su banda', function () {
    $this->tutoring->subject->update(['counts_teacher_hours' => true]);
    TeacherPosition::query()->where('teacher_id', $this->teacher->id)->update(['base_hours' => 3]);
    $validator = app(ScheduleValidator::class);
    $validator->forget();

    expect($validator->teacherTermHours($this->teacher, $this->world->term)['assigned_hours'])->toBe(6)
        ->and($validator->validateTeacher($this->teacher, $this->world->term)->forRule('HC-08'))->not->toBe([]);
});

it('PL-02 y HC-01: la tutoría sigue ocupando el horario del docente', function () {
    $clash = $this->world->group('B', [[2, '09:00', '10:00']]);
    $validator = app(ScheduleValidator::class);
    $validator->forget();

    expect(collect($validator->checkTeacher($clash, $this->teacher)->violations())->pluck('rule')->all())->toContain('HC-01');
});

it('PL-02 y SEL-05: la banda de la orden de selección no cuenta la tutoría', function () {
    $hours = app(SelectionGroupsQuery::class)->hoursByTeacher($this->world->selection, [$this->teacher->id]);
    $entry = app(FinishMyLoad::class)->handle($this->world->selection, $this->teacher, false, $this->teacher->user);

    expect($hours[$this->teacher->id])->toBe(5)
        ->and($entry->fresh()->closed_hours)->toBe(5);
});

it('PL-02: la carga por docente no cuenta la tutoría', function () {
    app(RefreshTeacherTermLoads::class)->handle($this->world->term, [$this->teacher->id]);

    expect(TeacherTermLoad::query()->where('teacher_id', $this->teacher->id)->sole())->assigned_hours->toBe(5)->groups_count->toBe(2);
});

it('PL-02: al marcar la materia se recalculan los choques, la carga por docente y el ranking de sus grupos', function () {
    AcademicLoad::query()->update(['status' => LoadStatus::Staffing]);
    $this->tutoring->subject->update(['counts_teacher_hours' => true]);
    Queue::fake();

    app(SetSubjectTeacherHours::class)->handle($this->tutoring->subject, false, $this->world->admin);

    Queue::assertPushed(RecomputeScheduleConflicts::class, fn (RecomputeScheduleConflicts $job): bool => $job->termId === $this->world->term->id);
    Queue::assertPushed(RefreshTeacherLoads::class, fn (RefreshTeacherLoads $job): bool => $job->termId === $this->world->term->id);
    Queue::assertPushed(ComputeRankings::class, fn (ComputeRankings $job): bool => $job->departmentId === $this->tutoring->department_id);
});

it('PL-02: otro cambio de la materia o guardar sin cambio no recalcula nada', function () {
    AcademicLoad::query()->update(['status' => LoadStatus::Staffing]);
    Queue::fake();

    event(new SubjectUpdated($this->tutoring->subject, ['credits']));
    app(SetSubjectTeacherHours::class)->handle($this->tutoring->subject, false, $this->world->admin);

    Queue::assertNothingPushed();
});
