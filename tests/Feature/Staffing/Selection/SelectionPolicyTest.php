<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;

/*
| SelectionPolicy (tarea 1.33, diseño 6.8): maneja el admin; ven los jefes de ese departamento, la Subdirección y los
| docentes de la cola; elige el docente con renglón propio.
*/

function selectionTeacher(Selection $selection, bool $inQueue = true, bool $active = true, ?Department $department = null, bool $withRole = true): User
{
    $user = $withRole ? User::factory()->withRole(Role::Docente)->create() : User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $user->id, 'is_active' => $active]);
    TeacherDepartment::factory()->create(['teacher_id' => $teacher->id, 'department_id' => ($department ?? $selection->department)->id]);

    if ($inQueue) {
        SelectionEntry::factory()->for($selection)->create(['teacher_id' => $teacher->id]);
    }

    return $user;
}

function publishedSelection(array $attributes = []): Selection
{
    return Selection::factory()->create(['status' => SelectionStatus::Published, ...$attributes]);
}

it('solo admin maneja la selección', function () {
    $selection = Selection::factory()->create();
    $admin = User::factory()->withRole(Role::Admin)->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();

    expect($admin->can('manage', $selection))->toBeTrue()
        ->and($jefe->can('manage', $selection))->toBeFalse()
        ->and(selectionTeacher($selection)->can('manage', $selection))->toBeFalse();
});

it('un docente de otro departamento no ve la selección', function () {
    $selection = publishedSelection();
    $otro = selectionTeacher($selection, inQueue: false, department: Department::factory()->create());

    expect($otro->can('view', $selection))->toBeFalse()
        ->and(selectionTeacher($selection)->can('view', $selection))->toBeTrue();
});

it('un docente con renglón pero adscrito a otro departamento no ve ni elige', function () {
    $selection = publishedSelection();
    $user = selectionTeacher($selection, department: Department::factory()->create());

    expect($user->can('view', $selection))->toBeFalse()
        ->and($user->can('pickOwn', $selection))->toBeFalse();
});

it('un docente inactivo con renglón no ve ni elige', function () {
    $selection = publishedSelection();
    $user = selectionTeacher($selection, active: false);

    expect($user->can('view', $selection))->toBeFalse()
        ->and($user->can('pickOwn', $selection))->toBeFalse();
});

it('un docente no ve una selección en borrador ni cancelada', function () {
    $draft = Selection::factory()->create();
    $cancelled = Selection::factory()->create(['status' => SelectionStatus::Cancelled]);

    expect(selectionTeacher($draft)->can('view', $draft))->toBeFalse()
        ->and(selectionTeacher($cancelled)->can('view', $cancelled))->toBeFalse();
});

it('el jefe y la subdirección ven la selección de su departamento; el jefe de otro no', function () {
    $selection = Selection::factory()->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $selection->modality_id, 'department_id' => $selection->department_id])->create();
    $directivo = User::factory()->withRole(Role::Directivo)->create();
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    expect($jefe->can('view', $selection))->toBeTrue()
        ->and($jpd->can('view', $selection))->toBeTrue()
        ->and($directivo->can('view', $selection))->toBeTrue()
        ->and($subdireccion->can('view', $selection))->toBeTrue()
        ->and($jefeOtro->can('view', $selection))->toBeFalse();
});

it('solo el docente con renglón propio elige', function () {
    $selection = publishedSelection();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();

    expect(selectionTeacher($selection)->can('pickOwn', $selection))->toBeTrue()
        ->and(selectionTeacher($selection, inQueue: false)->can('pickOwn', $selection))->toBeFalse()
        ->and($jefe->can('pickOwn', $selection))->toBeFalse();
});

it('quien tiene renglón pero no el puesto de docente no elige', function () {
    $selection = publishedSelection();
    $sinPuesto = selectionTeacher($selection, withRole: false);

    expect($sinPuesto->can('pickOwn', $selection))->toBeFalse();
});
