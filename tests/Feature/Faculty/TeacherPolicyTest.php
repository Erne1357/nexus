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
| TeacherPolicy (spec 4.2 con la adenda del 30 de octubre de 2026: docentes, jefes y Subdirección son de todo el
| Tec), según reglas-de-negocio.md, "Aprobaciones y permisos":
| - el admin y la Subdirección, a todos los docentes, también a los que no tienen departamento;
| - el jefe de departamento de D (sin campus) y el jefe de proyecto docente de D (en un campus), a los docentes con
|   una adscripción sin borrar a D, aunque su principal sea otra;
| - la baja (desactivar o reactivar), solo el admin y la Subdirección; los jefes y los JPD solo quitan de su
|   departamento («Quitar de <código>» en la pantalla);
| - nadie se da de baja ni se quita a sí mismo (supuesto por confirmar);
| - el coordinador, el docente y un usuario inactivo, nada. Nombrar jefes, dar de alta usuarios y reiniciar
|   contraseñas es solo del admin.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->sc = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->ii = Department::factory()->create(['code' => 'II', 'name' => 'Ingeniería Industrial']);
    $this->cb = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);

    // Principal en II y otra adscripción a SC.
    $this->inSc = Teacher::factory()->adscribedTo($this->ii)->adscribedTo($this->sc, primary: false)->create();
    // Solo II.
    $this->onlyIi = Teacher::factory()->adscribedTo($this->ii)->create();
    // Estuvo en SC, pero esa adscripción se quitó; queda II.
    $this->formerSc = Teacher::factory()->adscribedTo($this->ii)->create();
    TeacherDepartment::factory()->for($this->formerSc)->for($this->sc)->create(['is_primary' => false])->delete();
    // Sin departamento.
    $this->loose = Teacher::factory()->create();
});

/** Las abilities sobre un docente que da la cobertura de una adscripción (la baja va aparte). */
const TEACHER_ABILITIES = ['view', 'update', 'managePositions', 'manageAvailability', 'manageExternalLoads'];

function teacherAbilitiesOf(User $user, Teacher $teacher): array
{
    return collect(TEACHER_ABILITIES)->mapWithKeys(fn (string $ability): array => [$ability => $user->can($ability, $teacher)])->all();
}

function allTeacherAbilities(bool $allowed): array
{
    return array_fill_keys(TEACHER_ABILITIES, $allowed);
}

/**
 * El docente cuyo usuario es `$user`, adscrito a `$department`.
 */
function teacherOfUser(User $user, Department $department): Teacher
{
    return Teacher::factory()->adscribedTo($department)->create(['user_id' => $user->id]);
}

it('al admin le permite todo, también sobre un docente sin departamento', function () {
    $admin = User::factory()->admin()->create();
    $subject = Subject::factory()->create(['department_id' => $this->cb->id]);

    expect($admin->can('viewAny', Teacher::class))->toBeTrue()
        ->and($admin->can('create', Teacher::class))->toBeTrue()
        ->and($admin->can('create', [Teacher::class, $this->cb]))->toBeTrue()
        ->and($admin->can('appointHeads', Teacher::class))->toBeTrue()
        ->and($admin->can('provisionUsers', Teacher::class))->toBeTrue()
        ->and($admin->can('resetPassword', $this->loose))->toBeTrue()
        ->and($admin->can('manageQualification', [$this->onlyIi, $subject]))->toBeTrue()
        ->and($admin->can('removeAdscription', [Teacher::class, $this->onlyIi->adscriptions()->sole()]))->toBeTrue();

    foreach ([$this->inSc, $this->onlyIi, $this->formerSc, $this->loose] as $teacher) {
        expect(teacherAbilitiesOf($admin, $teacher))->toBe(allTeacherAbilities(true))
            ->and($admin->can('deactivate', $teacher))->toBeTrue();
    }
});

it('la Subdirección, una para todo el Tec, cubre a todos los docentes, también sin departamento, y los da de baja', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $basic = Subject::factory()->create(['department_id' => $this->cb->id]);

    expect($subdireccion->can('viewAny', Teacher::class))->toBeTrue()
        ->and($subdireccion->can('create', [Teacher::class, $this->cb]))->toBeTrue()
        ->and($subdireccion->can('manageQualification', [$this->onlyIi, $basic]))->toBeTrue()
        ->and($subdireccion->can('removeAdscription', [Teacher::class, $this->onlyIi->adscriptions()->sole()]))->toBeTrue();

    foreach ([$this->inSc, $this->onlyIi, $this->formerSc, $this->loose] as $teacher) {
        expect(teacherAbilitiesOf($subdireccion, $teacher))->toBe(allTeacherAbilities(true))
            ->and($subdireccion->can('deactivate', $teacher))->toBeTrue();
    }
});

it('el jefe de SC, de todo el Tec, cubre a un adscrito a SC aunque su principal sea otra, pero no lo da de baja', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();

    expect($jefe->can('viewAny', Teacher::class))->toBeTrue()
        ->and($jefe->can('create', Teacher::class))->toBeTrue()
        ->and($jefe->can('create', [Teacher::class, $this->sc]))->toBeTrue()
        ->and(teacherAbilitiesOf($jefe, $this->inSc))->toBe(allTeacherAbilities(true))
        ->and($jefe->can('deactivate', $this->inSc))->toBeFalse();
});

it('el jefe de proyecto docente de SC en C2 cubre a los adscritos a SC, porque el docente no es de un campus', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();

    expect($jpd->can('viewAny', Teacher::class))->toBeTrue()
        ->and($jpd->can('create', [Teacher::class, $this->sc]))->toBeTrue()
        ->and(teacherAbilitiesOf($jpd, $this->inSc))->toBe(allTeacherAbilities(true))
        ->and($jpd->can('deactivate', $this->inSc))->toBeFalse();
});

it('el jefe de SC no cubre a uno solo de II, ni a uno cuya adscripción a SC se quitó, ni a uno sin departamento', function (Role $role, array $scope) {
    $jefe = User::factory()->withRole($role, ['department_id' => $this->sc->id, ...$scope])->create();

    expect(teacherAbilitiesOf($jefe, $this->onlyIi))->toBe(allTeacherAbilities(false))
        ->and(teacherAbilitiesOf($jefe, $this->formerSc))->toBe(allTeacherAbilities(false))
        ->and(teacherAbilitiesOf($jefe, $this->loose))->toBe(allTeacherAbilities(false))
        ->and($jefe->can('create', [Teacher::class, $this->ii]))->toBeFalse();
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, []],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['campus_id' => Campus::factory()->create()->id]],
]);

it('al coordinador, al docente y a un usuario inactivo no les permite nada', function (Closure $makeUser) {
    $user = $makeUser($this);

    expect($user->can('viewAny', Teacher::class))->toBeFalse()
        ->and($user->can('create', Teacher::class))->toBeFalse()
        ->and($user->can('create', [Teacher::class, $this->sc]))->toBeFalse()
        ->and($user->can('removeAdscription', [Teacher::class, $this->onlyIi->adscriptions()->sole()]))->toBeFalse()
        ->and($user->can('manageQualification', [$this->onlyIi, Subject::factory()->create(['department_id' => $this->ii->id])]))->toBeFalse();

    foreach ([$this->inSc, $this->onlyIi, $this->loose] as $teacher) {
        expect(teacherAbilitiesOf($user, $teacher))->toBe(allTeacherAbilities(false))
            ->and($user->can('deactivate', $teacher))->toBeFalse();
    }
})->with([
    'coordinador' => fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->c2->id, 'career_id' => careerKey(20)])->create(),
    'docente' => fn ($test) => User::factory()->withRole(Role::Docente)->create(),
    'jefe inactivo' => fn ($test) => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $test->ii->id])->create(['is_active' => false]),
    'Subdirección inactiva' => fn ($test) => User::factory()->withRole(Role::Subdireccion)->create(['is_active' => false]),
    'admin inactivo' => fn ($test) => User::factory()->admin()->create(['is_active' => false]),
]);

it('manageQualification del jefe de SC con una materia de CB es falso, y con una de SC es verdadero', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();
    $basic = Subject::factory()->create(['department_id' => $this->cb->id]);
    $systems = Subject::factory()->create(['department_id' => $this->sc->id]);

    expect($jefe->can('manageQualification', [$this->inSc, $basic]))->toBeFalse()
        ->and($jefe->can('manageQualification', [$this->inSc, $systems]))->toBeTrue()
        // Una materia de su departamento no le abre a un docente que no cubre.
        ->and($jefe->can('manageQualification', [$this->onlyIi, $systems]))->toBeFalse();
});

it('removeAdscription cubre la adscripción del departamento del jefe o del JPD y no la de otro', function (Role $role, array $scope) {
    $jefe = User::factory()->withRole($role, ['department_id' => $this->sc->id, ...$scope])->create();
    $own = $this->inSc->adscriptions()->where('department_id', $this->sc->id)->sole();
    $other = $this->inSc->adscriptions()->where('department_id', $this->ii->id)->sole();

    expect($jefe->can('removeAdscription', [Teacher::class, $own]))->toBeTrue()
        ->and($jefe->can('removeAdscription', [Teacher::class, $other]))->toBeFalse();
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, []],
    'jefe de proyecto docente de SC en C2' => [Role::JefeProyectoDocente, fn () => ['campus_id' => Campus::where('code', 'C2')->value('id')]],
]);

it('nadie se da de baja ni se quita a sí mismo de un departamento, ni el admin ni la Subdirección (supuesto por confirmar)', function (Closure $makeUser) {
    $user = $makeUser($this);
    $own = teacherOfUser($user, $this->sc);

    expect($user->can('deactivate', $own))->toBeFalse()
        ->and($user->can('removeAdscription', [Teacher::class, $own->adscriptions()->sole()]))->toBeFalse()
        // Sus datos sí los ve y los edita quien lo cubre.
        ->and($user->can('update', $own))->toBeTrue();
})->with([
    'admin' => fn ($test) => User::factory()->admin()->create(),
    'Subdirección' => fn ($test) => User::factory()->withRole(Role::Subdireccion)->create(),
    'jefe de SC' => fn ($test) => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $test->sc->id])->create(),
]);

it('appointHeads, provisionUsers y resetPassword son solo del admin', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, $scope)->create();

    expect($user->can('appointHeads', Teacher::class))->toBeFalse()
        ->and($user->can('provisionUsers', Teacher::class))->toBeFalse()
        ->and($user->can('resetPassword', $this->inSc))->toBeFalse();
})->with([
    'Subdirección' => [Role::Subdireccion, []],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['campus_id' => Campus::factory()->create()->id, 'department_id' => departmentKey(10)]],
]);

it('no deja borrar docentes a nadie: se dan de baja', function () {
    expect(method_exists(TeacherPolicy::class, 'delete'))->toBeFalse()
        ->and(method_exists(TeacherPolicy::class, 'forceDelete'))->toBeFalse();
});
