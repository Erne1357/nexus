<?php

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\User;

/*
| CatalogReviewPolicy (reglas-de-negocio.md, "Validación de catálogos" y fila «Validar catálogos del
| departamento»): solo el jefe del departamento corrige, valida, reabre y pone observaciones (supuesto por
| confirmar: ni el admin ni Subdirección); ven y resuelven observaciones ese jefe, el admin y Subdirección.
| Los salones los corrige solo si el perfil del campus de la revisión lo permite (`heads_edit_rooms`).
*/

const REVIEW_ABILITIES = ['view', 'correct', 'validate', 'reopen', 'flag', 'resolveFlag'];

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'settings' => [...CampusProfile::DEFAULTS, 'heads_edit_rooms' => true]]);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->general = CatalogReview::factory()->general(Catalog::Subjects)->create(['department_id' => departmentKey(10)]);
    $this->roomsC1 = CatalogReview::factory()->rooms($this->c1)->create(['department_id' => departmentKey(10)]);
    $this->roomsC2 = CatalogReview::factory()->rooms($this->c2)->create(['department_id' => departmentKey(10)]);
});

/**
 * @return array<string, bool>
 */
function reviewAbilitiesOf(User $user, CatalogReview $review): array
{
    return collect(REVIEW_ABILITIES)->mapWithKeys(fn (string $ability) => [$ability => $user->can($ability, $review)])->all();
}

function reviewAbilities(bool ...$allowed): array
{
    return array_combine(REVIEW_ABILITIES, $allowed);
}

it('permite según el rol y su alcance en la revisión general del departamento', function (Role $role, array $scope, array $expected) {
    $user = User::factory()->withRole($role, $scope)->create();

    expect(reviewAbilitiesOf($user, $this->general))->toBe(reviewAbilities(...$expected));
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], [true, true, true, true, true, true]],
    'subdirección: ve y resuelve observaciones' => [Role::Subdireccion, [], [true, false, false, false, false, true]],
    'jefe de proyecto docente del departamento' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->c1->id, 'department_id' => departmentKey(10)], [false, false, false, false, false, false]],
    'coordinador' => [Role::Coordinador, fn () => ['campus_id' => test()->c1->id, 'career_id' => careerKey(20)], [false, false, false, false, false, false]],
    'docente' => [Role::Docente, [], [false, false, false, false, false, false]],
    'jefe de otro departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(11)], [false, false, false, false, false, false]],
]);

it('al admin le deja ver y resolver observaciones, pero no corregir, validar, reabrir ni observar', function () {
    $admin = User::factory()->admin()->create();

    expect(reviewAbilitiesOf($admin, $this->general))->toBe(reviewAbilities(true, false, false, false, false, true))
        ->and(reviewAbilitiesOf($admin, $this->roomsC1))->toBe(reviewAbilities(true, false, false, false, false, true));
});

it('el jefe corrige los salones solo si el perfil del campus de la revisión lo permite; lo demás, en cualquier campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();

    expect(reviewAbilitiesOf($jefe, $this->roomsC1))->toBe(reviewAbilities(true, true, true, true, true, true))
        ->and(reviewAbilitiesOf($jefe, $this->roomsC2))->toBe(reviewAbilities(true, false, true, true, true, true));
});

it('niega corregir salones en un campus sin salones aunque el perfil deje corregirlos', function () {
    $campus = Campus::factory()->create(['code' => 'XX', 'settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false, 'heads_edit_rooms' => true]]);
    $review = CatalogReview::factory()->rooms($campus)->create(['department_id' => departmentKey(10)]);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();

    expect($jefe->can('correct', $review))->toBeFalse();
});

it('niega los salones de un campus desactivado al jefe y a Subdirección', function (Role $role, array $scope) {
    $this->c1->update(['is_active' => false]);
    $user = User::factory()->withRole($role, $scope)->create();

    expect(reviewAbilitiesOf($user, $this->roomsC1->fresh()))->toBe(reviewAbilities(false, false, false, false, false, false));
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'subdirección' => [Role::Subdireccion, []],
]);

it('niega al jefe con alcance en otro campus los salones de este', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect(reviewAbilitiesOf($jefe, $this->roomsC1))->toBe(reviewAbilities(false, false, false, false, false, false));
});

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect(reviewAbilitiesOf($admin, $this->general))->toBe(reviewAbilities(false, false, false, false, false, false));
});

it('el tablero de avance y el recordatorio son de Subdirección y del admin (tarea 2.5, supuesto por confirmar)', function (string $who, bool $allowed) {
    $user = match ($who) {
        'admin' => User::factory()->admin()->create(),
        'subdirección' => User::factory()->withRole(Role::Subdireccion, [])->create(),
        'jefe de departamento' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create(),
        'jefe de proyecto docente' => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => test()->c1->id, 'department_id' => departmentKey(10)])->create(),
        'coordinador' => User::factory()->withRole(Role::Coordinador, ['campus_id' => test()->c1->id, 'career_id' => careerKey(20)])->create(),
        'docente' => User::factory()->withRole(Role::Docente, [])->create(),
    };

    expect($user->can('viewProgress', CatalogReview::class))->toBe($allowed)
        ->and($user->can('remind', CatalogReview::class))->toBe($allowed);
})->with([
    ['admin', true],
    ['subdirección', true],
    ['jefe de departamento', false],
    ['jefe de proyecto docente', false],
    ['coordinador', false],
    ['docente', false],
]);

it('la misma persona con otro perfil activo es rechazada', function () {
    $jefe = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
        ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])
        ->create();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->withRole(Role::Docente)->create();

    actingAsRole($jefe, Role::JefeDepartamento);
    expect(reviewAbilitiesOf($jefe, $this->general))->toBe(reviewAbilities(true, true, true, true, true, true));

    actingAsRole($jefe, Role::Coordinador);
    expect(reviewAbilitiesOf($jefe, $this->general))->toBe(reviewAbilities(false, false, false, false, false, false));

    actingAsRole($subdireccion, Role::Subdireccion);
    expect(reviewAbilitiesOf($subdireccion, $this->general))->toBe(reviewAbilities(true, false, false, false, false, true))
        ->and($subdireccion->can('viewProgress', CatalogReview::class))->toBeTrue()
        ->and($subdireccion->can('remind', CatalogReview::class))->toBeTrue();

    actingAsRole($subdireccion, Role::Docente);
    expect(reviewAbilitiesOf($subdireccion, $this->general))->toBe(reviewAbilities(false, false, false, false, false, false))
        ->and($subdireccion->can('viewProgress', CatalogReview::class))->toBeFalse()
        ->and($subdireccion->can('remind', CatalogReview::class))->toBeFalse();
});
