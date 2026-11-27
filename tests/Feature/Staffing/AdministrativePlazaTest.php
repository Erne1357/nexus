<?php

use App\Modules\Core\Settings\SettingRegistry;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Actions\Selection\FinishMyLoad;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Queries\TeacherLoadRow;
use App\Modules\Staffing\Queries\TeacherLoadsBoardQuery;
use App\Modules\Staffing\Rules\AdministrativeCap;
use App\Modules\Staffing\Rules\CandidateMarks;
use Illuminate\Validation\ValidationException;
use Tests\Support\Staffing\SelectionWorld;

/*
| PL-03 y HC-22 (tarea 1.80, confirmado el 27 de noviembre de 2026): la plaza administrativa (apoyo y asistencia) no lleva
| banda de ±2, puede no tener clases y no puede pasar de 42 h entre administrativas, clases y externas.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create();
});

/** Un docente en la cola cuya única plaza es de apoyo y asistencia de `$hours` h. */
function administrativeTeacher(SelectionWorld $world, int $hours = 36): Teacher
{
    $teacher = $world->teacher(base: 20);
    TeacherPosition::query()->where('teacher_id', $teacher->id)->delete();
    TeacherPosition::factory()->for($teacher)->create(['kind' => PositionKind::Apoyo, 'base_hours' => $hours, 'category' => 'IA08']);

    return $teacher;
}

function administrativeErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('HC-22: AssignTeacher veta a quien con plaza administrativa pasaría de 42 h (36 + 8) y permite 36 + 6', function () {
    $teacher = administrativeTeacher($this->world);
    $over = $this->world->group('A', [[1, '09:00', '10:00']], theory: 8, practice: 0);
    $fits = $this->world->group('B', [[2, '09:00', '10:00']], theory: 6, practice: 0);

    $errors = administrativeErrors(fn () => app(AssignTeacher::class)->handle($over, $teacher, $this->world->admin, 'motivo'));
    $assignment = app(AssignTeacher::class)->handle($fits, $teacher, $this->world->admin, 'motivo');

    expect($errors['teacher'][0])->toContain('pasa el tope de su plaza administrativa (42 h)')
        ->and($over->fresh()->teacher_id)->toBeNull()
        ->and($assignment->teacher_id)->toBe($teacher->id);
});

it('HC-22: tomar un grupo en la orden de selección que pasa de 42 h se veta; 36 + 6 sí se toma', function () {
    $teacher = administrativeTeacher($this->world);
    $room = $this->world->room('A1');
    $over = $this->world->group('A', [[1, '09:00', '10:00']], theory: 8, practice: 0);
    $fits = $this->world->group('B', [[2, '09:00', '10:00']], theory: 6, practice: 0);

    $errors = administrativeErrors(fn () => app(PickSelectionGroup::class)->handle($this->world->selection, $teacher, $over, $room, $teacher->user));
    $picked = app(PickSelectionGroup::class)->handle($this->world->selection, $teacher, $fits, $room, $teacher->user);

    expect($errors['teacher'][0])->toContain('pasa el tope de su plaza administrativa (42 h)')
        ->and($over->fresh()->teacher_id)->toBeNull()
        ->and($picked)->toBeInstanceOf(GroupTeacherAssignment::class);
});

it('HC-22: el validador lo reporta como choque duro y el ranking lo marca «sin_cupo»', function () {
    $teacher = administrativeTeacher($this->world);
    $over = $this->world->group('A', [[1, '09:00', '10:00']], theory: 8, practice: 0);

    $validator = app(ScheduleValidator::class);
    $validator->forget();
    $result = $validator->checkTeacher($over, $teacher);

    expect($result->hasHard())->toBeTrue()
        ->and($result->forRule('HC-22'))->toHaveCount(1)
        ->and(CandidateMarks::of($result))->toBe(['sin_cupo']);
});

it('PL-03 y SEL-05: con plaza administrativa termina sin la casilla con 0 h y con 10 h, y no pasa a la ronda extra', function (int $hours) {
    $teacher = administrativeTeacher($this->world, 20);

    if ($hours > 0) {
        app(PickSelectionGroup::class)->handle($this->world->selection, $teacher, $this->world->group('A', [[1, '09:00', '10:00']], theory: $hours, practice: 0), $this->world->room('A1'), $teacher->user);
    }

    $entry = app(FinishMyLoad::class)->handle($this->world->selection, $teacher, false, $teacher->user);

    expect($entry->fresh())->status->toBe(SelectionEntryStatus::Done)->closed_hours->toBe($hours)->out_of_band_acknowledged_at->toBeNull();
})->with([0, 10]);

it('PL-03: quien no es de plaza administrativa sigue con su banda y la casilla (sin cambio)', function () {
    $teacher = $this->world->teacher(base: 20);

    $errors = administrativeErrors(fn () => app(FinishMyLoad::class)->handle($this->world->selection, $teacher, false, $teacher->user));

    expect($errors)->toHaveKey('acknowledged');
});

it('HC-22: el motor solo le deja lo que le queda hasta el tope de 42 h', function () {
    $teacher = administrativeTeacher($this->world);
    $this->world->group('A', [[1, '09:00', '10:00']], theory: 3, practice: 0, attributes: ['teacher_id' => $teacher->id]);

    $validator = app(ScheduleValidator::class);
    $validator->forget();
    $hours = $validator->teacherTermHours($teacher, $this->world->term);

    expect($hours)->administrative_hours->toBe(36)->administrative_cap->toBe(42)->assigned_hours->toBe(3)->remaining_hours->toBe(3);
});

it('PL-03 y CF-S7: el parámetro staffing.administrative_max_hours vale 42 por omisión y acepta enteros de 1 a 60', function () {
    $definition = app(SettingRegistry::class)->get('staffing.administrative_max_hours');

    expect($definition->default)->toBe(42)
        ->and($definition->accepts(1))->toBeTrue()
        ->and($definition->accepts(60))->toBeTrue()
        ->and($definition->accepts(0))->toBeFalse()
        ->and($definition->accepts(61))->toBeFalse()
        ->and(AdministrativeCap::current())->toBe(42);
});

it('PL-03: la carga por docente guarda las horas administrativas y su tope, y la fila las muestra sin banda', function () {
    $teacher = administrativeTeacher($this->world);
    $this->world->group('A', [[1, '09:00', '10:00']], theory: 4, practice: 0, attributes: ['teacher_id' => $teacher->id]);

    app(RefreshTeacherTermLoads::class)->handle($this->world->term, [$teacher->id]);
    $load = TeacherTermLoad::query()->where('teacher_id', $teacher->id)->sole();

    expect($load)->administrative_hours->toBe(36)->administrative_cap_hours->toBe(42)->teaching_hours->toBe(0)->remaining_hours->toBe(2);

    $board = app(TeacherLoadsBoardQuery::class)->forDepartment($this->world->term, $this->world->department);
    $row = collect($board->rows)->firstWhere('teacherId', $teacher->id);

    expect($row->administrativeSummary())->toBe('Plaza administrativa: 36 h · clases 4 h · 40 de 42 h')
        ->and($row->bandLabel())->toBeNull()
        ->and($row->hourNotice())->toBeNull()
        ->and($row->status())->toBe(TeacherLoadRow::PART);
});
