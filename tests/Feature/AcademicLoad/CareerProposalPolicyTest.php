<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| CareerProposalPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos": arma, corrige y envía la
| propuesta el coordinador de la carrera y campus; la ve también la subdirección, solo lectura (supuesto por
| confirmar). El admin, siempre. Los jefes deciden su parte desde la bandeja del departamento (tarea 2.8).
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->career = Career::query()->findOrFail(careerKey(20));
    $this->proposal = CareerProposal::factory()->create(['campus_id' => $this->c1->id, 'career_id' => $this->career->id]);
});

/**
 * Lo que `$user` puede hacer con la propuesta de la prueba: [armar, ver, corregir, enviar].
 *
 * @return list<bool>
 */
function proposalAbilitiesOf(User $user): array
{
    return [
        $user->can('create', [CareerProposal::class, test()->c1, test()->career]),
        $user->can('view', test()->proposal),
        $user->can('update', test()->proposal),
        $user->can('submit', test()->proposal),
    ];
}

it('permite según el rol y su alcance en la propia carrera y campus', function (Role $role, array $scope, array $abilities) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->c1->id, ...$scope])->create();

    expect(proposalAbilitiesOf($user))->toBe($abilities);
})->with([
    'coordinador de la carrera' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)], [true, true, true, true]],
    'subdirección: ve, no edita' => [Role::Subdireccion, [], [false, true, false, false]],
    'jefe de departamento: no edita' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], [false, false, false, false]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)], [false, false, false, false]],
    'docente' => [Role::Docente, [], [false, false, false, false]],
]);

it('niega todo al jefe del departamento que coordina la carrera', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->career->department_id])->create();

    expect(proposalAbilitiesOf($jefe))->toBe([false, false, false, false]);
});

it('la subdirección de todo el Tec ve la propuesta de cualquier campus', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    expect(proposalAbilitiesOf($subdireccion))->toBe([false, true, false, false]);
});

it('niega todo al coordinador de otra carrera en el mismo campus', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(21)])->create();

    expect(proposalAbilitiesOf($coordinador))->toBe([false, false, false, false]);
});

it('niega todo al coordinador de la misma carrera en otro campus', function () {
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

    expect(proposalAbilitiesOf($coordinador))->toBe([false, false, false, false])
        ->and($coordinador->can('create', [CareerProposal::class, $this->c2, $this->career]))->toBeTrue();
});

it('al admin le permite todo', function () {
    expect(proposalAbilitiesOf(User::factory()->admin()->create()))->toBe([true, true, true, true]);
});

it('niega todo a un admin desactivado y a un coordinador desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])->create(['is_active' => false]);

    expect(proposalAbilitiesOf($admin))->toBe([false, false, false, false])
        ->and(proposalAbilitiesOf($coordinador))->toBe([false, false, false, false]);
});

it('la misma persona con otro perfil activo es rechazada', function () {
    $persona = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->create();

    actingAsRole($persona, Role::Coordinador);
    expect(proposalAbilitiesOf($persona))->toBe([true, true, true, true]);

    actingAsRole($persona, Role::JefeDepartamento);
    expect(proposalAbilitiesOf($persona))->toBe([false, false, false, false]);
});
