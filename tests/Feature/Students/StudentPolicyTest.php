<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\Student;

/*
| StudentPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Consultar alumnos"): el admin ve a
| todos; la subdirección, a los de su campus; el jefe de departamento, a los de las carreras de su departamento en
| su campus; el coordinador, a los de su carrera en su campus. Nadie más, y nadie los edita: vienen del SII.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->department = Department::factory()->create();
    $this->career = Career::factory()->for($this->department)->create();
    $this->student = Student::factory()->for(StudyPlan::factory()->for($this->career))->create(['campus_id' => $this->campus->id]);
});

/**
 * @param  array<string, string>  $scope
 */
function studentViewer(Role $role, array $scope = []): User
{
    return User::factory()->withRole($role, $scope)->create();
}

it('al admin le deja ver la lista y a cualquier alumno', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('viewAny', Student::class))->toBeTrue()
        ->and($admin->can('view', $this->student))->toBeTrue();
});

it('deja ver al alumno a quien cubre su carrera en su campus: :dataset', function (User $user) {
    expect($user->can('viewAny', Student::class))->toBeTrue()
        ->and($user->can('view', $this->student))->toBeTrue();
})->with([
    'la subdirección de su campus' => fn () => studentViewer(Role::Subdireccion, ['campus_id' => $this->campus->id]),
    'el jefe del departamento de su carrera' => fn () => studentViewer(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->department->id]),
    'el coordinador de su carrera' => fn () => studentViewer(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id]),
]);

it('no deja ver al alumno a quien no lo cubre: :dataset', function (User $user) {
    expect($user->can('view', $this->student))->toBeFalse();
})->with([
    'la subdirección de otro campus' => fn () => studentViewer(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id]),
    'el jefe de otro departamento' => fn () => studentViewer(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => Department::factory()->create()->id]),
    'el jefe de su departamento en otro campus' => fn () => studentViewer(Role::JefeDepartamento, ['campus_id' => Campus::factory()->create()->id, 'department_id' => $this->department->id]),
    'el coordinador de otra carrera' => fn () => studentViewer(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->for($this->department)->create()->id]),
    'el coordinador de su carrera en otro campus' => fn () => studentViewer(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $this->career->id]),
]);

it('no deja ver alumnos al jefe de proyecto docente ni al docente, aun en su departamento', function (Role $role) {
    $user = studentViewer($role, ['campus_id' => $this->campus->id, 'department_id' => $this->department->id]);

    expect($user->can('viewAny', Student::class))->toBeFalse()
        ->and($user->can('view', $this->student))->toBeFalse();
})->with([Role::JefeProyectoDocente, Role::Docente]);

it('nadie edita ni borra alumnos: vienen del SII', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('update', $this->student))->toBeFalse()
        ->and($admin->can('delete', $this->student))->toBeFalse();
});
