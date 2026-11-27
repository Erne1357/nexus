<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentQuery;

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

it('la misma persona con otro perfil activo es rechazada', function () {
    $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])
        ->withRole(Role::Docente)->create();

    actingAsRole($user, Role::Coordinador);
    expect($user->can('viewAny', Student::class))->toBeTrue()
        ->and($user->can('view', $this->student))->toBeTrue();

    actingAsRole($user, Role::Docente);
    expect($user->can('viewAny', Student::class))->toBeFalse()
        ->and($user->can('view', $this->student))->toBeFalse();
});

/*
| AZ-S17 (MS7): los datos de un alumno son de su campus y de su modalidad. Un coordinador de PRES limitado a un campus
| solo ve a los de ese campus (supuesto por confirmar).
*/

it('AZ-S17: el coordinador de PRES limitado a C2 ve a los alumnos de C2 de su carrera y no a los de C1', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $pres = modalityOf('PRES');
    $plan = StudyPlan::factory()->for($this->career);
    $inC2 = Student::factory()->for($plan)->inModality($pres)->create(['campus_id' => $c2->id]);
    $inC1 = Student::factory()->for($plan)->inModality($pres)->create(['campus_id' => $c1->id]);
    $coordinator = studentViewer(Role::Coordinador, ['modality_id' => $pres->id, 'campus_id' => $c2->id, 'career_id' => $this->career->id]);

    expect($coordinator->can('view', $inC2))->toBeTrue()
        ->and($coordinator->can('view', $inC1))->toBeFalse();
});

it('AZ-S17: el coordinador de PRES sin campus ve a los alumnos de ambos campus', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $pres = modalityOf('PRES');
    $plan = StudyPlan::factory()->for($this->career);
    $coordinator = studentViewer(Role::Coordinador, ['modality_id' => $pres->id, 'career_id' => $this->career->id]);

    expect($coordinator->can('view', Student::factory()->for($plan)->inModality($pres)->create(['campus_id' => $c1->id])))->toBeTrue()
        ->and($coordinator->can('view', Student::factory()->for($plan)->inModality($pres)->create(['campus_id' => $c2->id])))->toBeTrue();
});

it('AZ-S17: el coordinador de EAD no ve a los alumnos de PRES', function () {
    $ead = modalityOf('EAD');
    $ead->update(['is_active' => true]);
    $pres = modalityOf('PRES');
    $plan = StudyPlan::factory()->for($this->career);
    $coordinator = studentViewer(Role::Coordinador, ['modality_id' => $ead->id, 'career_id' => $this->career->id]);

    expect($coordinator->can('view', Student::factory()->for($plan)->inModality($pres)->create(['campus_id' => $this->campus->id])))->toBeFalse()
        ->and($coordinator->can('view', Student::factory()->for($plan)->inModality($ead)->create(['campus_id' => $this->campus->id])))->toBeTrue();
});

it('AZ-S17: la lista de alumnos coincide con la policy por modalidad y campus', function () {
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $ead = modalityOf('EAD');
    $ead->update(['is_active' => true]);
    $pres = modalityOf('PRES');
    $plan = StudyPlan::factory()->for($this->career);
    $mine = Student::factory()->for($plan)->inModality($pres)->create(['campus_id' => $c2->id, 'control_number' => '21000001']);
    Student::factory()->for($plan)->inModality($pres)->create(['campus_id' => $c1->id, 'control_number' => '21000002']);
    Student::factory()->for($plan)->inModality($ead)->create(['campus_id' => $c2->id, 'control_number' => '21000003']);
    $coordinator = studentViewer(Role::Coordinador, ['modality_id' => $pres->id, 'campus_id' => $c2->id, 'career_id' => $this->career->id]);
    $this->actingAs($coordinator);
    app()->forgetScopedInstances();

    $listed = app(StudentQuery::class)->forTable(app(Actor::class), $c2, [], '', 25)->pluck('control_number')->all();

    expect($listed)->toBe([$mine->control_number]);
});

it('M10: con EAD inactiva, Subdirección y el jefe ven al alumno de EAD en la lista y en la ficha; el coordinador de PRES no', function () {
    $ead = modalityOf('EAD');
    $ead->update(['is_active' => false]);
    $student = Student::factory()->for(StudyPlan::factory()->for($this->career))->inModality($ead)->create(['campus_id' => $this->campus->id, 'control_number' => '21000077']);
    $viewers = [
        'subdireccion' => [studentViewer(Role::Subdireccion), true],
        'jefe' => [studentViewer(Role::JefeDepartamento, ['department_id' => $this->department->id]), true],
        'coordinador PRES' => [studentViewer(Role::Coordinador, ['modality_id' => modalityOf('PRES')->id, 'career_id' => $this->career->id]), false],
    ];

    foreach ($viewers as [$user, $sees]) {
        $this->actingAs($user);
        app()->forgetScopedInstances();
        $listed = app(StudentQuery::class)->forTable(app(Actor::class), $this->campus, [], '', 25)->pluck('control_number')->all();

        expect($user->can('view', $student))->toBe($sees)
            ->and(in_array('21000077', $listed, true))->toBe($sees);
    }
});
