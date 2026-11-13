<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| AssignTeacher: asigna el docente de un grupo y deja el historial (HC-16; reglas-de-negocio.md, "Ranking docente").
| Solo con la carga en `staffing` o `adjusting` (confirmado el 7 de noviembre de 2026).
*/

beforeEach(function () {
    $this->actor = User::factory()->admin()->create();
    $this->group = Group::factory()->create(['code' => 'D']);
    GroupSession::factory()->for($this->group)->on(1, '10:00', '12:00')->create();
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
        'term_id' => $this->group->term_id,
        'modality_id' => $this->group->modality_id,
        'department_id' => $this->group->department_id,
    ]);
    $this->teachers = collect(range(1, 3))->map(function (int $rank): Teacher {
        $teacher = Teacher::factory()->create();
        TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();
        StaffingRanking::factory()->create(['group_id' => $this->group->id, 'teacher_id' => $teacher->id, 'rank' => $rank]);

        return $teacher;
    });
});

function assignTeacher(Group $group, Teacher $teacher, User $actor, ?string $reason = null): GroupTeacherAssignment
{
    return app(AssignTeacher::class)->handle($group, $teacher, $actor, $reason);
}

function assignmentErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('asigna al primer lugar sin motivo, deja el historial y la bitácora', function () {
    $assignment = assignTeacher($this->group, $this->teachers[0], $this->actor);

    $entry = Activity::query()->where('event', 'teacher_assigned')->sole();

    expect($this->group->fresh()->teacher_id)->toBe($this->teachers[0]->id)
        ->and($assignment)
        ->group_id->toBe($this->group->id)
        ->teacher_id->toBe($this->teachers[0]->id)
        ->term_id->toBe($this->group->term_id)
        ->modality_id->toBe($this->group->modality_id)
        ->assigned_by->toBe($this->actor->id)
        ->ranking_position_at_assignment->toBe(1)
        ->ranking_size_at_assignment->toBe(3)
        ->reason->toBeNull()
        ->replaced_at->toBeNull()
        ->and($entry->causer->is($this->actor))->toBeTrue()
        ->and($entry->subject->is($this->group))->toBeTrue()
        ->and($entry->properties['position'])->toBe('lugar 1 de 3');
});

it('la asignación copia la modalidad de su grupo (ampliación de la ronda 2)', function () {
    $ead = Modality::query()->where('code', 'EAD')->sole();
    $ead->update(['is_active' => true]);
    $this->group->update(['modality_id' => $ead->id]);
    $this->load->update(['modality_id' => $ead->id]);

    $assignment = assignTeacher($this->group, $this->teachers[0], $this->actor);

    expect($assignment->fresh()->modality->code)->toBe('EAD');
});

it('HC-16: rechaza al tercer lugar sin motivo y no escribe nada', function () {
    $errors = assignmentErrors(fn () => assignTeacher($this->group, $this->teachers[2], $this->actor, '   '));

    expect($errors['reason'])->toBe(['Ocupa el lugar 3 de 3: escribe por qué no eliges al primer lugar (HC-16).'])
        ->and($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->count())->toBe(0);
});

it('HC-16: acepta al tercer lugar con motivo y guarda «3 de N»', function () {
    $assignment = assignTeacher($this->group, $this->teachers[2], $this->actor, '  Prefiere este grupo.  ');

    expect($assignment)
        ->ranking_position_at_assignment->toBe(3)
        ->ranking_size_at_assignment->toBe(3)
        ->reason->toBe('Prefiere este grupo.')
        ->and(Activity::query()->where('event', 'teacher_assigned')->sole()->properties['reason'])->toBe('Prefiere este grupo.');
});

it('HC-16: un docente fuera del ranking pide motivo y queda sin lugar', function () {
    $outsider = Teacher::factory()->create();
    TeacherPosition::factory()->for($outsider)->tiempoCompleto()->create();

    $errors = assignmentErrors(fn () => assignTeacher($this->group, $outsider, $this->actor));
    $assignment = assignTeacher($this->group, $outsider, $this->actor, 'Es el único con la especialidad.');

    expect($errors['reason'])->toBe(['No está en el ranking del grupo: escribe el motivo (HC-16).'])
        ->and($assignment->ranking_position_at_assignment)->toBeNull()
        ->and($assignment->ranking_size_at_assignment)->toBe(3);
});

it('rechaza si la carga no está en asignación ni ajustes y acepta en ajustes', function () {
    $this->load->update(['status' => LoadStatus::Proposed]);

    $errors = assignmentErrors(fn () => assignTeacher($this->group, $this->teachers[0], $this->actor));

    expect($errors['group'])->toBe(['La carga está en Propuesta: los docentes se asignan en Asignación o Ajustes.'])
        ->and($this->group->fresh()->teacher_id)->toBeNull();

    $this->load->update(['status' => LoadStatus::Adjusting]);

    expect(assignTeacher($this->group, $this->teachers[0], $this->actor)->exists)->toBeTrue();
});

it('rechaza a un docente inactivo', function () {
    $this->teachers[0]->update(['is_active' => false]);

    $errors = assignmentErrors(fn () => assignTeacher($this->group, $this->teachers[0], $this->actor));

    expect($errors)->toHaveKey('teacher')
        ->and(GroupTeacherAssignment::query()->count())->toBe(0);
});

it('HC-01: rechaza al docente que ya da clase a esa hora y no escribe nada', function () {
    $busy = Group::factory()->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'teacher_id' => $this->teachers[0]->id, 'code' => 'F']);
    GroupSession::factory()->for($busy)->on(1, '11:00', '13:00')->create();

    $errors = assignmentErrors(fn () => assignTeacher($this->group, $this->teachers[0], $this->actor));

    expect($errors)->toHaveKey('teacher')
        ->and($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'teacher_assigned')->count())->toBe(0);
});

it('al reemplazar el docente marca la asignación anterior como reemplazada', function () {
    $first = assignTeacher($this->group, $this->teachers[0], $this->actor);
    $second = assignTeacher($this->group, $this->teachers[1], $this->actor, 'Cambio de plantilla.');

    expect($first->fresh())
        ->replaced_at->not->toBeNull()
        ->replaced_by->toBe($this->actor->id)
        ->and($second->fresh()->replaced_at)->toBeNull()
        ->and($this->group->fresh()->teacher_id)->toBe($this->teachers[1]->id)
        ->and(GroupTeacherAssignment::query()->current()->where('group_id', $this->group->id)->count())->toBe(1);
});

it('asignar otra vez al mismo docente no cambia nada ni deja otra fila', function () {
    $first = assignTeacher($this->group, $this->teachers[0], $this->actor);
    $again = assignTeacher($this->group, $this->teachers[0], $this->actor);

    expect($again->is($first))->toBeTrue()
        ->and(GroupTeacherAssignment::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'teacher_assigned')->count())->toBe(1);
});
