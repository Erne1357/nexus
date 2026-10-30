<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Policies\TeacherPolicy;

/*
| TeacherPolicy (spec 4.2), según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Administrar docentes",
| confirmada el 30 de octubre de 2026):
| - el admin, todo;
| - el jefe de departamento y el jefe de proyecto docente de D en C, los docentes con una adscripción sin borrar a D
|   en C, aunque su principal sea otra;
| - la subdirección de C, los adscritos en C, de cualquier departamento;
| - el coordinador, el docente y un usuario inactivo, nada.
| Nombrar jefes, dar de alta usuarios y reiniciar contraseñas es solo del admin.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);

    // Principal en II de C2 y otra adscripción a SC de C2.
    $this->scInC2 = Teacher::factory()->adscribedTo($this->ii, $this->c2)->adscribedTo($this->sc, $this->c2, primary: false)->create();
    // Solo SC de C1.
    $this->scInC1 = Teacher::factory()->adscribedTo($this->sc, $this->c1)->create();
    // Solo II de C2.
    $this->iiInC2 = Teacher::factory()->adscribedTo($this->ii, $this->c2)->create();
    // Estuvo en SC de C2, pero esa adscripción se quitó; queda II de C1.
    $this->formerSc = Teacher::factory()->adscribedTo($this->ii, $this->c1)->create();
    TeacherDepartment::factory()->for($this->formerSc)->for($this->sc)->for($this->c2)->create(['is_primary' => false])->delete();
});

/** Las abilities sobre un docente que da la cobertura de una adscripción. */
const TEACHER_ABILITIES = ['view', 'update', 'deactivate', 'managePositions', 'manageAvailability', 'manageExternalLoads'];

function teacherAbilitiesOf(User $user, Teacher $teacher): array
{
    return collect(TEACHER_ABILITIES)->mapWithKeys(fn (string $ability): array => [$ability => $user->can($ability, $teacher)])->all();
}

function allTeacherAbilities(bool $allowed): array
{
    return array_fill_keys(TEACHER_ABILITIES, $allowed);
}

it('al admin le permite todo, también sobre un docente sin adscripciones', function () {
    $admin = User::factory()->admin()->create();
    $loose = Teacher::factory()->create();
    $subject = Subject::factory()->create(['department_id' => $this->cb->id]);

    expect($admin->can('viewAny', Teacher::class))->toBeTrue()
        ->and($admin->can('create', Teacher::class))->toBeTrue()
        ->and($admin->can('create', [Teacher::class, $this->c1, $this->cb]))->toBeTrue()
        ->and($admin->can('appointHeads', Teacher::class))->toBeTrue()
        ->and($admin->can('provisionUsers', Teacher::class))->toBeTrue()
        ->and($admin->can('resetPassword', $loose))->toBeTrue()
        ->and($admin->can('manageQualification', [$this->scInC1, $subject]))->toBeTrue()
        ->and($admin->can('removeAdscription', [Teacher::class, $this->scInC1->adscriptions()->sole()]))->toBeTrue();

    foreach ([$this->scInC2, $this->scInC1, $this->iiInC2, $this->formerSc, $loose] as $teacher) {
        expect(teacherAbilitiesOf($admin, $teacher))->toBe(allTeacherAbilities(true));
    }
});

it('el jefe y el jefe de proyecto docente de SC en C2 cubren a un adscrito a SC en C2 aunque su principal sea otra', function (Role $role) {
    $jefe = User::factory()->withRole($role, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();

    expect($jefe->can('viewAny', Teacher::class))->toBeTrue()
        ->and($jefe->can('create', Teacher::class))->toBeTrue()
        ->and($jefe->can('create', [Teacher::class, $this->c2, $this->sc]))->toBeTrue()
        ->and(teacherAbilitiesOf($jefe, $this->scInC2))->toBe(allTeacherAbilities(true));
})->with(['jefe de departamento' => Role::JefeDepartamento, 'jefe de proyecto docente' => Role::JefeProyectoDocente]);

it('el jefe de SC en C2 no cubre a uno solo de SC en C1, ni a uno de II en C2, ni a uno cuya adscripción a SC en C2 se quitó', function (Role $role) {
    $jefe = User::factory()->withRole($role, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();

    expect(teacherAbilitiesOf($jefe, $this->scInC1))->toBe(allTeacherAbilities(false))
        ->and(teacherAbilitiesOf($jefe, $this->iiInC2))->toBe(allTeacherAbilities(false))
        ->and(teacherAbilitiesOf($jefe, $this->formerSc))->toBe(allTeacherAbilities(false))
        ->and($jefe->can('create', [Teacher::class, $this->c1, $this->sc]))->toBeFalse()
        ->and($jefe->can('create', [Teacher::class, $this->c2, $this->ii]))->toBeFalse();
})->with(['jefe de departamento' => Role::JefeDepartamento, 'jefe de proyecto docente' => Role::JefeProyectoDocente]);

it('la subdirección de C2 cubre a cualquier adscrito en C2 y no a los de C1', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();

    expect($subdireccion->can('viewAny', Teacher::class))->toBeTrue()
        ->and($subdireccion->can('create', [Teacher::class, $this->c2, $this->cb]))->toBeTrue()
        ->and($subdireccion->can('create', [Teacher::class, $this->c1, $this->cb]))->toBeFalse()
        ->and(teacherAbilitiesOf($subdireccion, $this->scInC2))->toBe(allTeacherAbilities(true))
        ->and(teacherAbilitiesOf($subdireccion, $this->iiInC2))->toBe(allTeacherAbilities(true))
        ->and(teacherAbilitiesOf($subdireccion, $this->scInC1))->toBe(allTeacherAbilities(false))
        ->and(teacherAbilitiesOf($subdireccion, $this->formerSc))->toBe(allTeacherAbilities(false));
});

it('al coordinador, al docente y a un usuario inactivo no les permite nada', function (Closure $makeUser) {
    $user = $makeUser($this);

    expect($user->can('viewAny', Teacher::class))->toBeFalse()
        ->and($user->can('create', Teacher::class))->toBeFalse()
        ->and($user->can('create', [Teacher::class, $this->c2, $this->sc]))->toBeFalse()
        ->and($user->can('removeAdscription', [Teacher::class, $this->iiInC2->adscriptions()->sole()]))->toBeFalse()
        ->and($user->can('manageQualification', [$this->iiInC2, Subject::factory()->create(['department_id' => $this->ii->id])]))->toBeFalse();

    foreach ([$this->scInC2, $this->scInC1, $this->iiInC2] as $teacher) {
        expect(teacherAbilitiesOf($user, $teacher))->toBe(allTeacherAbilities(false));
    }
})->with([
    'coordinador' => fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->c2->id, 'career_id' => careerKey(20)])->create(),
    'docente' => fn ($test) => User::factory()->withRole(Role::Docente, ['campus_id' => $test->c2->id])->create(),
    'jefe inactivo' => fn ($test) => User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $test->c2->id, 'department_id' => $test->ii->id])->create(['is_active' => false]),
    'admin inactivo' => fn ($test) => User::factory()->admin()->create(['is_active' => false]),
]);

it('manageQualification del jefe de SC con una materia de CB es falso, y con una de SC es verdadero', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
    $basic = Subject::factory()->create(['department_id' => $this->cb->id]);
    $systems = Subject::factory()->create(['department_id' => $this->sc->id]);

    expect($jefe->can('manageQualification', [$this->scInC2, $basic]))->toBeFalse()
        ->and($jefe->can('manageQualification', [$this->scInC2, $systems]))->toBeTrue()
        // Una materia de su departamento no le abre a un docente que no cubre.
        ->and($jefe->can('manageQualification', [$this->scInC1, $systems]))->toBeFalse();
});

it('la subdirección califica en cualquier materia a los docentes de su campus', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
    $basic = Subject::factory()->create(['department_id' => $this->cb->id]);

    expect($subdireccion->can('manageQualification', [$this->iiInC2, $basic]))->toBeTrue()
        ->and($subdireccion->can('manageQualification', [$this->scInC1, $basic]))->toBeFalse();
});

it('removeAdscription solo cubre la adscripción de su departamento y campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();
    $own = $this->scInC2->adscriptions()->where('department_id', $this->sc->id)->sole();
    $other = $this->scInC2->adscriptions()->where('department_id', $this->ii->id)->sole();

    expect($jefe->can('removeAdscription', [Teacher::class, $own]))->toBeTrue()
        ->and($jefe->can('removeAdscription', [Teacher::class, $other]))->toBeFalse();
});

it('appointHeads, provisionUsers y resetPassword son solo del admin', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, ...$scope])->create();

    expect($user->can('appointHeads', Teacher::class))->toBeFalse()
        ->and($user->can('provisionUsers', Teacher::class))->toBeFalse()
        ->and($user->can('resetPassword', $this->scInC2))->toBeFalse();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
]);

it('no deja borrar docentes a nadie: se dan de baja', function () {
    expect(method_exists(TeacherPolicy::class, 'delete'))->toBeFalse()
        ->and(method_exists(TeacherPolicy::class, 'forceDelete'))->toBeFalse();
});
