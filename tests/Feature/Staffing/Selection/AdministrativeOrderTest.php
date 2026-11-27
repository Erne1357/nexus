<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Staffing\Actions\Selection\RecomputeSelectionOrder;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Queries\AdministrativePostsQuery;
use App\Modules\Staffing\Queries\SelectionOrderQuery;
use Carbon\CarbonImmutable;
use Tests\Support\Staffing\SelectionWorld;

/*
| SEL-07 con puesto administrativo (tarea 1.54; decisión del usuario, confirmado el 26 de noviembre de 2026): primero los
| docentes con nombramiento vigente en un puesto administrativo o con plaza de apoyo, luego los demás.
*/

beforeEach(function () {
    $this->world = SelectionWorld::create(SelectionStatus::Draft);
});

/** Un docente de la cola con su renglón del CSV (escalón y criterios dados), sin turnos calculados. */
function csvTeacher(string $name, int $tier, ?User $user = null): Teacher
{
    $world = test()->world;
    $teacher = Teacher::factory()->create(['name' => $name, 'user_id' => ($user ?? User::factory()->create())->id, 'is_active' => true]);
    TeacherDepartment::factory()->create(['teacher_id' => $teacher->id, 'department_id' => $world->department->id]);
    SelectionEntry::factory()->for($world->selection)->create([
        'teacher_id' => $teacher->id, 'source' => SelectionEntrySource::Csv, 'tier' => $tier, 'plaza_tier' => $tier,
        'failed_criteria' => 0, 'seniority_years' => 10, 'base_hours' => 40,
    ]);

    return $teacher;
}

function recomputeOrder(): array
{
    app(RecomputeSelectionOrder::class)->handle(test()->world->selection->fresh());

    return array_column(app(SelectionOrderQuery::class)->forSelection(test()->world->selection->fresh()), 'name');
}

it('SEL-07: un jefe de departamento va antes que un tiempo completo sin puesto', function () {
    csvTeacher('Docente TC', 0);
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    csvTeacher('Docente Jefe', 3, $head);

    expect(recomputeOrder())->toBe(['Docente Jefe', 'Docente TC']);
});

it('SEL-07: dentro de cada grupo se conserva el orden de siempre', function () {
    csvTeacher('Sin puesto uno', 0);
    csvTeacher('Sin puesto tres', 2);
    $coord = User::factory()->withRole(Role::Subdireccion)->create();
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    csvTeacher('Subdirectora', 2, $coord);
    csvTeacher('Jefe', 1, $head);

    expect(recomputeOrder())->toBe(['Jefe', 'Subdirectora', 'Sin puesto uno', 'Sin puesto tres']);
});

it('SEL-07: una plaza de apoyo vigente cuenta como puesto administrativo', function () {
    csvTeacher('Docente TC', 0);
    $support = csvTeacher('Apoyo', 3);
    TeacherPosition::factory()->for($support)->create(['kind' => PositionKind::Apoyo, 'base_hours' => 10, 'valid_from' => '2020-01-01', 'valid_to' => null]);

    expect(recomputeOrder())->toBe(['Apoyo', 'Docente TC']);
});

it('SEL-07: un nombramiento terminado o por empezar no cuenta', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'America/Ciudad_Juarez'));
    csvTeacher('Docente TC', 0);
    $ended = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id, 'valid_from' => '2020-01-01', 'valid_to' => '2026-06-30'])->create();
    csvTeacher('Jefe anterior', 3, $ended);

    expect(recomputeOrder())->toBe(['Docente TC', 'Jefe anterior']);
});

it('SEL-07: quien solo tiene el puesto de docente no es administrativo', function () {
    $posts = app(AdministrativePostsQuery::class)->forTeachers([csvTeacher('Docente', 0, User::factory()->withRole(Role::Docente)->create())->id], CarbonImmutable::now());

    expect($posts)->toBe([]);
});

it('SEL-07: el recálculo en borrador aplica el puesto administrativo y la vista del orden lo muestra', function () {
    csvTeacher('Docente TC', 0);
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->world->department->id])->create();
    csvTeacher('Docente Jefe', 3, $head);
    recomputeOrder();

    $rows = app(SelectionOrderQuery::class)->forSelection($this->world->selection->fresh());

    expect($rows[0])->toMatchArray(['name' => 'Docente Jefe', 'position' => 1, 'admin_post' => Role::JefeDepartamento->label()])
        ->and($rows[1])->toMatchArray(['name' => 'Docente TC', 'position' => 2, 'admin_post' => null]);
});
