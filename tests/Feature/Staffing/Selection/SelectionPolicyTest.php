<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;

/*
| SelectionPolicy (tarea 1.33, diseño 6.8): maneja el admin; ven los jefes de ese departamento, la Subdirección y los
| docentes de la cola; elige el docente con renglón propio.
*/

function selectionTeacher(Selection $selection, bool $inQueue = true): User
{
    $user = User::factory()->withRole(Role::Docente)->create();
    $teacher = Teacher::factory()->create(['user_id' => $user->id]);

    if ($inQueue) {
        SelectionEntry::factory()->for($selection)->create(['teacher_id' => $teacher->id]);
    }

    return $user;
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
    $selection = Selection::factory()->create();
    $otro = selectionTeacher($selection, inQueue: false);

    expect($otro->can('view', $selection))->toBeFalse()
        ->and(selectionTeacher($selection)->can('view', $selection))->toBeTrue();
});

it('el jefe y la subdirección ven la selección de su departamento; el jefe de otro no', function () {
    $selection = Selection::factory()->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    expect($jefe->can('view', $selection))->toBeTrue()
        ->and($subdireccion->can('view', $selection))->toBeTrue()
        ->and($jefeOtro->can('view', $selection))->toBeFalse();
});

it('solo el docente con renglón propio elige', function () {
    $selection = Selection::factory()->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();

    expect(selectionTeacher($selection)->can('pickOwn', $selection))->toBeTrue()
        ->and(selectionTeacher($selection, inQueue: false)->can('pickOwn', $selection))->toBeFalse()
        ->and($jefe->can('pickOwn', $selection))->toBeFalse();
});
