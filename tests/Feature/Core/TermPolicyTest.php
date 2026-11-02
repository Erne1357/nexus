<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\TermPolicy;

/*
| TermPolicy. Fila "Abrir periodo, calendario, perfil de campus" de reglas-de-negocio.md: `admin` y
| `subdireccion` (supuesto por confirmar). Nadie borra periodos.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->term = Term::factory()->create();
});

function termAbilities(User $user, Term $term): array
{
    return [
        $user->can('viewAny', Term::class),
        $user->can('create', Term::class),
        $user->can('view', $term),
        $user->can('update', $term),
        $user->can('makeCurrent', $term),
        $user->can('manageCalendar', $term),
        $user->can('open', $term),
    ];
}

it('permite todo al admin y a la subdirección', function () {
    $admin = User::factory()->admin()->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->campus->id])->create();

    expect(termAbilities($admin, $this->term))->each->toBeTrue()
        ->and(termAbilities($subdireccion, $this->term))->each->toBeTrue();
});

it('niega todo a los demás roles', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, ...$scope])->create();

    expect(termAbilities($user, $this->term))->each->toBeFalse();
})->with([
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect(termAbilities($admin, $this->term))->each->toBeFalse();
});

it('no deja borrar periodos a nadie, ni al admin', function () {
    $admin = User::factory()->admin()->create();

    expect(method_exists(TermPolicy::class, 'delete'))->toBeFalse()
        ->and($admin->can('delete', $this->term))->toBeFalse();
});
