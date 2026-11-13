<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| InterdepartmentalAgreementPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos" (filas de acuerdos entre
| departamentos; confirmado el 7 de noviembre de 2026): lo pide y lo cancela el jefe del departamento que lo pide
| (`from`); lo acepta o rechaza el jefe del departamento al que va dirigido (`to`); lo ven la subdirección, el jefe y
| el jefe de proyecto docente de cualquiera de los dos. El admin, siempre.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->from = Department::query()->findOrFail(departmentKey(10));
    $this->to = Department::query()->findOrFail(departmentKey(11));
    $this->agreement = InterdepartmentalAgreement::factory()->create([
        'campus_id' => $this->c1->id,
        'from_department_id' => $this->from->id,
        'to_department_id' => $this->to->id,
    ]);
});

/**
 * Lo que `$user` puede hacer con el acuerdo de la prueba: [viewAny, view, decide, cancel, request].
 *
 * @return list<bool>
 */
function agreementAbilitiesOf(User $user): array
{
    $agreement = InterdepartmentalAgreement::query()->findOrFail(test()->agreement->id);

    return [
        $user->can('viewAny', InterdepartmentalAgreement::class),
        $user->can('view', $agreement),
        $user->can('decide', $agreement),
        $user->can('cancel', $agreement),
        $user->can('request', [InterdepartmentalAgreement::class, modalityOf('PRES'), test()->c1, test()->from]),
    ];
}

it('permite según el puesto y su departamento', function (Role $role, array $scope, array $abilities) {
    $user = User::factory()->withRole($role, $scope)->create();

    expect(agreementAbilitiesOf($user))->toBe($abilities);
})->with([
    'jefe del departamento que pide: ve, pide y cancela' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], [true, true, false, true, true]],
    'jefe del departamento al que va dirigido: ve y decide' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(11)], [true, true, true, false, false]],
    'jefe de otro departamento: nada sobre este acuerdo' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(12)], [true, false, false, false, false]],
    'jefe de proyecto docente del que pide: solo ve' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->c1->id, 'department_id' => departmentKey(10)], [true, true, false, false, false]],
    'jefe de proyecto docente del que decide: solo ve' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->c1->id, 'department_id' => departmentKey(11)], [true, true, false, false, false]],
    'jefe de proyecto docente de otro departamento' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->c1->id, 'department_id' => departmentKey(12)], [true, false, false, false, false]],
    'subdirección: solo ve' => [Role::Subdireccion, [], [true, true, false, false, false]],
    'coordinador' => [Role::Coordinador, fn () => ['campus_id' => test()->c1->id, 'career_id' => careerKey(20)], [false, false, false, false, false]],
    'docente' => [Role::Docente, [], [false, false, false, false, false]],
]);

it('al admin le permite todo', function () {
    expect(agreementAbilitiesOf(User::factory()->admin()->create()))->toBe([true, true, true, true, true]);
});

it('AZ-S17: un jefe de proyecto docente con otro campus ve el acuerdo de su modalidad', function () {
    $user = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect(agreementAbilitiesOf($user))->toBe([true, true, false, false, false]);
});

it('AZ-S17: niega a un alcance del departamento en otra modalidad, salvo ver la lista', function (Role $role, array $scope) {
    modalityOf('EAD')->update(['is_active' => true]);
    $user = User::factory()->withRole($role, ['modality_id' => modalityOf('EAD')->id, ...$scope])->create();

    expect(agreementAbilitiesOf($user))->toBe([true, false, false, false, false]);
})->with([
    'jefe de proyecto docente del que pide' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
    'jefe de proyecto docente del que decide' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(11)]],
]);

it('el jefe de departamento de todo el Tec actúa en cualquier campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();
    $inC2 = InterdepartmentalAgreement::factory()->create([
        'campus_id' => $this->c2->id,
        'from_department_id' => $this->from->id,
        'to_department_id' => $this->to->id,
    ]);

    expect($jefe->can('cancel', $inC2))->toBeTrue()
        ->and($jefe->can('request', [InterdepartmentalAgreement::class, modalityOf('PRES'), $this->c2, $this->from]))->toBeTrue()
        ->and($jefe->can('decide', $inC2))->toBeFalse();
});

it('MS6: el grupo compartido no guarda campus: lo cancela quien pide con el campus del grupo, y sin él solo quien pide sin límite de campus', function () {
    $share = InterdepartmentalAgreement::factory()->sharedGroup()->create([
        'from_department_id' => $this->from->id,
        'to_department_id' => $this->to->id,
    ]);
    $inC1 = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])->create();

    expect($inC1->can('cancel', [$share, $this->c1->id]))->toBeTrue()
        ->and($inC1->can('cancel', [$share, $this->c2->id]))->toBeFalse()
        ->and($inC1->can('cancel', $share))->toBeFalse();

    vacate(Role::JefeDepartamento, departmentKey(10));
    $tecWide = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10), 'valid_from' => today()->toDateString()])->create();

    expect($tecWide->can('cancel', $share))->toBeTrue()
        ->and($tecWide->can('cancel', [$share, $this->c2->id]))->toBeTrue();
});

it('MS6: en un préstamo manda el campus del salón, no el que da quien llama', function () {
    $inC2 = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect($inC2->can('cancel', [$this->agreement, $this->c2->id]))->toBeFalse();
});

it('niega todo a un admin desactivado y a un jefe desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(['is_active' => false]);

    expect(agreementAbilitiesOf($admin))->toBe([false, false, false, false, false])
        ->and(agreementAbilitiesOf($jefe))->toBe([false, false, false, false, false]);
});

it('la misma persona con otro perfil activo es rechazada', function () {
    $jefe = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])
        ->create();

    actingAsRole($jefe, Role::JefeDepartamento);
    expect(agreementAbilitiesOf($jefe))->toBe([true, true, false, true, true]);

    actingAsRole($jefe, Role::Coordinador);
    expect(agreementAbilitiesOf($jefe))->toBe([false, false, false, false, false]);
});
