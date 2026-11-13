<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\UnassignTeacher;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UnassignTeacher: quita el docente de un grupo, con motivo, y lo deja en el historial (supuesto por confirmar).
*/

beforeEach(function () {
    $this->actor = User::factory()->admin()->create();
    $this->group = Group::factory()->create(['code' => 'D']);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
        'term_id' => $this->group->term_id,
        'modality_id' => $this->group->modality_id,
        'department_id' => $this->group->department_id,
    ]);
    $this->teacher = Teacher::factory()->create();
    TeacherPosition::factory()->for($this->teacher)->tiempoCompleto()->create();
    StaffingRanking::factory()->create(['group_id' => $this->group->id, 'teacher_id' => $this->teacher->id, 'rank' => 1]);
    app(AssignTeacher::class)->handle($this->group, $this->teacher, $this->actor);
});

it('quita el docente con motivo, marca la asignación reemplazada y deja bitácora', function () {
    app(UnassignTeacher::class)->handle($this->group, $this->actor, 'Renunció.');

    $entry = Activity::query()->where('event', 'teacher_unassigned')->sole();

    expect($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->sole())
        ->replaced_at->not->toBeNull()
        ->replaced_by->toBe($this->actor->id)
        ->and($entry->properties['reason'])->toBe('Renunció.')
        ->and($entry->causer->is($this->actor))->toBeTrue();
});

it('rechaza sin motivo y no cambia nada', function () {
    expect(fn () => app(UnassignTeacher::class)->handle($this->group, $this->actor, '  '))->toThrow(ValidationException::class);

    expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and(GroupTeacherAssignment::query()->current()->count())->toBe(1);
});

it('rechaza si el grupo no tiene docente', function () {
    app(UnassignTeacher::class)->handle($this->group, $this->actor, 'Renunció.');

    expect(fn () => app(UnassignTeacher::class)->handle($this->group, $this->actor, 'Otra vez.'))->toThrow(ValidationException::class);
});

it('rechaza si la carga ya no está en asignación ni ajustes', function () {
    $this->load->update(['status' => LoadStatus::Closed]);

    expect(fn () => app(UnassignTeacher::class)->handle($this->group, $this->actor, 'Renunció.'))->toThrow(ValidationException::class)
        ->and($this->group->fresh()->teacher_id)->toBe($this->teacher->id);
});
