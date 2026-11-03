<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| ProposalReviewPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos": la parte de un departamento en una
| propuesta de carrera la acepta o la regresa el jefe de ese departamento, y ven la bandeja del departamento
| quienes ven su carga (supuestos por confirmar). El admin, siempre.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::query()->findOrFail(departmentKey(10));
    $this->review = ProposalReview::factory()->create([
        'career_proposal_id' => CareerProposal::factory()->submitted()->create(['campus_id' => $this->c1->id])->id,
        'department_id' => $this->department->id,
    ]);
});

/**
 * Lo que `$user` puede hacer en la bandeja del departamento de la prueba en C1: [ver la bandeja, decidir la parte].
 *
 * @return list<bool>
 */
function inboxAbilitiesOf(User $user): array
{
    return [
        $user->can('viewAny', [ProposalReview::class, test()->c1, test()->department]),
        $user->can('decide', ProposalReview::query()->findOrFail(test()->review->id)),
    ];
}

it('permite según el rol y su alcance en el propio departamento', function (Role $role, array $scope, array $abilities) {
    $user = User::factory()->withRole($role, $scope)->create();

    expect(inboxAbilitiesOf($user))->toBe($abilities);
})->with([
    'jefe de departamento: ve y decide' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], [true, true]],
    'jefe de proyecto docente: ve, no decide' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->c1->id, 'department_id' => departmentKey(10)], [true, false]],
    'subdirección: ve, no decide' => [Role::Subdireccion, [], [true, false]],
    'coordinador' => [Role::Coordinador, fn () => ['campus_id' => test()->c1->id, 'career_id' => careerKey(20)], [false, false]],
    'docente' => [Role::Docente, [], [false, false]],
]);

it('niega todo al jefe de otro departamento', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();

    expect(inboxAbilitiesOf($jefe))->toBe([false, false]);
});

it('niega todo a un alcance del departamento en otro campus', function (Role $role) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect(inboxAbilitiesOf($user))->toBe([false, false])
        ->and($user->can('viewAny', [ProposalReview::class, $this->c2, $this->department]))->toBeTrue();
})->with([Role::JefeDepartamento, Role::JefeProyectoDocente]);

it('el jefe de departamento de todo el Tec decide la parte de su departamento en cualquier campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();
    $inC2 = ProposalReview::factory()->create([
        'career_proposal_id' => CareerProposal::factory()->submitted()->create(['campus_id' => $this->c2->id])->id,
        'department_id' => $this->department->id,
    ]);

    expect($jefe->can('decide', $inC2))->toBeTrue()
        ->and($jefe->can('viewAny', [ProposalReview::class, $this->c2, $this->department]))->toBeTrue();
});

it('al admin le permite todo', function () {
    expect(inboxAbilitiesOf(User::factory()->admin()->create()))->toBe([true, true]);
});

it('niega todo a un admin desactivado y a un jefe desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(['is_active' => false]);

    expect(inboxAbilitiesOf($admin))->toBe([false, false])
        ->and(inboxAbilitiesOf($jefe))->toBe([false, false]);
});

it('la misma persona con otro perfil activo es rechazada', function () {
    $jefe = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])
        ->create();

    actingAsRole($jefe, Role::JefeDepartamento);
    expect(inboxAbilitiesOf($jefe))->toBe([true, true]);

    actingAsRole($jefe, Role::Coordinador);
    expect(inboxAbilitiesOf($jefe))->toBe([false, false]);
});
