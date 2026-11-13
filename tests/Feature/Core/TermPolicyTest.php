<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\TermPolicy;

/*
| TermPolicy. Fila "Abrir periodo, calendario, perfil de modalidad" de reglas-de-negocio.md: `admin` y
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

it('la misma persona con otro perfil activo es rechazada', function () {
    $user = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->campus->id])
        ->withRole(Role::Docente)->create();

    actingAsRole($user, Role::Subdireccion);
    expect(termAbilities($user, $this->term))->each->toBeTrue();

    actingAsRole($user, Role::Docente);
    expect(termAbilities($user, $this->term))->each->toBeFalse();
});

it('M4: abrir el periodo se decide por modalidad', function () {
    $active = modalityOf('PRES');
    $other = Modality::factory()->create();
    $inactive = Modality::factory()->inactive()->create();
    $admin = User::factory()->admin()->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['modality_id' => $active->id, 'campus_id' => $this->campus->id, 'career_id' => careerKey(20)])->create();

    expect($admin->can('open', [$this->term, $active]))->toBeTrue()
        ->and($admin->can('open', [$this->term, $inactive]))->toBeTrue()
        ->and($subdireccion->can('open', [$this->term, $active]))->toBeTrue()
        ->and($subdireccion->can('open', [$this->term, $other]))->toBeTrue()
        ->and($subdireccion->can('open', [$this->term, $inactive]))->toBeFalse()
        ->and($coordinador->can('open', [$this->term, $active]))->toBeFalse()
        ->and($coordinador->can('open', [$this->term, $other]))->toBeFalse();
});

it('M4: una Subdirección con nombramiento en un campus (columna de más, D3) abre en cualquier modalidad activa: el campus no cuenta', function () {
    $other = Modality::factory()->create();
    $scoped = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->campus->id])->create();

    expect($scoped->can('open', [$this->term, modalityOf('PRES')]))->toBeTrue()
        ->and($scoped->can('open', [$this->term, $other]))->toBeTrue();
});
