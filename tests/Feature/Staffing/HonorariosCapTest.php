<?php

use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Rules\CandidateMarks;
use Illuminate\Validation\ValidationException;
use Tests\Support\Staffing\SelectionWorld;

/*
| PL-04 y HC-22 (tarea 1.81, confirmado el 27 de noviembre de 2026): las horas que pasan de frente a grupo + 2 son extra
| (honorarios); las horas de la plaza más las extra no pueden pasar de 44 h.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create();
});

/** Un docente en la cola con plaza de tiempo completo (40 h, 22 h frente a grupo, banda de 20 a 24). */
function tcTeacher(SelectionWorld $world): Teacher
{
    $teacher = $world->teacher(base: 40);
    TeacherPosition::query()->where('teacher_id', $teacher->id)->delete();
    TeacherPosition::factory()->for($teacher)->create(['kind' => PositionKind::TiempoCompleto, 'base_hours' => 40, 'category' => 'E3817']);

    return $teacher;
}

function honorariosErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('HC-22 y PL-04: AssignTeacher veta a un TC de 40 h con 29 h (40 + 5 > 44) y permite 28 h (40 + 4 = 44)', function () {
    $teacher = tcTeacher($this->world);
    $over = $this->world->group('A', [[1, '09:00', '10:00']], theory: 29, practice: 0);
    $fits = $this->world->group('B', [[2, '09:00', '10:00']], theory: 28, practice: 0);

    $errors = honorariosErrors(fn () => app(AssignTeacher::class)->handle($over, $teacher, $this->world->admin, 'motivo'));
    $assignment = app(AssignTeacher::class)->handle($fits, $teacher, $this->world->admin, 'motivo');

    expect($errors['teacher'][0])->toContain('tope con honorarios (44 h)')
        ->and($over->fresh()->teacher_id)->toBeNull()
        ->and($assignment->teacher_id)->toBe($teacher->id);
});

it('HC-22 y PL-04: tomar un grupo en la orden de selección que pasa de 44 h se veta; 28 h sí se toma', function () {
    $teacher = tcTeacher($this->world);
    $room = $this->world->room('A1');
    $over = $this->world->group('A', [[1, '09:00', '10:00']], theory: 29, practice: 0);
    $fits = $this->world->group('B', [[2, '09:00', '10:00']], theory: 28, practice: 0);

    $errors = honorariosErrors(fn () => app(PickSelectionGroup::class)->handle($this->world->selection, $teacher, $over, $room, $teacher->user));
    $picked = app(PickSelectionGroup::class)->handle($this->world->selection, $teacher, $fits, $room, $teacher->user);

    expect($errors['teacher'][0])->toContain('tope con honorarios (44 h)')
        ->and($over->fresh()->teacher_id)->toBeNull()
        ->and($picked)->toBeInstanceOf(GroupTeacherAssignment::class);
});

it('HC-22 y PL-04: el validador lo reporta como choque duro y el ranking lo marca «sin_cupo»; con 24 h no hay nada', function () {
    $teacher = tcTeacher($this->world);
    $over = $this->world->group('A', [[1, '09:00', '10:00']], theory: 29, practice: 0);
    $within = $this->world->group('B', [[2, '09:00', '10:00']], theory: 24, practice: 0);

    $validator = app(ScheduleValidator::class);
    $validator->forget();
    $result = $validator->checkTeacher($over, $teacher);
    $validator->forget();

    expect($result->hasHard())->toBeTrue()
        ->and($result->forRule('HC-22'))->toHaveCount(1)
        ->and(CandidateMarks::of($result))->toContain('sin_cupo')
        ->and($validator->checkTeacher($within, $teacher)->forRule('HC-22'))->toHaveCount(0);
});

it('PL-04 y CF-S7: el parámetro staffing.max_hours_with_honorarios vale 44 por omisión y acepta enteros de 1 a 60', function () {
    $definition = app(SettingRegistry::class)->get('staffing.max_hours_with_honorarios');

    expect($definition->default)->toBe(44)
        ->and($definition->accepts(1))->toBeTrue()
        ->and($definition->accepts(60))->toBeTrue()
        ->and($definition->accepts(0))->toBeFalse()
        ->and($definition->accepts(61))->toBeFalse();
});
