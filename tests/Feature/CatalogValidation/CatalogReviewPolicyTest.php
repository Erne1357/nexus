<?php

use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| CatalogReviewPolicy (reglas-de-negocio.md, "Validación de catálogos" y fila «Validar catálogos del
| departamento»): solo el jefe del departamento corrige, valida, reabre y pone observaciones (supuesto por
| confirmar: ni el admin ni Subdirección); ven y resuelven observaciones ese jefe, el admin y Subdirección.
| Los salones de su departamento los corrige en cualquier campus, en una sola revisión para todos los campus (CV2 y
| decisión 6, confirmados el 12 de noviembre de 2026). Marcar un registro como revisado (CV7) es de quien valida.
*/

const REVIEW_ABILITIES = ['view', 'correct', 'validate', 'reopen', 'flag', 'resolveFlag', 'mark'];

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->general = CatalogReview::factory()->general(Catalog::Subjects)->create(['department_id' => departmentKey(10)]);
    $this->rooms = CatalogReview::factory()->rooms()->create(['department_id' => departmentKey(10)]);
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
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], [true, true, true, true, true, true, true]],
    'subdirección: ve y resuelve observaciones' => [Role::Subdireccion, [], [true, false, false, false, false, true, false]],
    'jefe de proyecto docente del departamento' => [Role::JefeProyectoDocente, fn () => ['campus_id' => test()->c1->id, 'department_id' => departmentKey(10)], [false, false, false, false, false, false, false]],
    'coordinador' => [Role::Coordinador, fn () => ['campus_id' => test()->c1->id, 'career_id' => careerKey(20)], [false, false, false, false, false, false, false]],
    'docente' => [Role::Docente, [], [false, false, false, false, false, false, false]],
    'jefe de otro departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(11)], [false, false, false, false, false, false, false]],
]);

it('al admin le deja ver y resolver observaciones, pero no corregir, validar, reabrir ni observar', function () {
    $admin = User::factory()->admin()->create();

    expect(reviewAbilitiesOf($admin, $this->general))->toBe(reviewAbilities(true, false, false, false, false, true, false))
        ->and(reviewAbilitiesOf($admin, $this->rooms))->toBe(reviewAbilities(true, false, false, false, false, true, false));
});

it('CV2: el jefe corrige los salones de su departamento en una sola revisión para todos los campus, sin depender del perfil', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();

    expect(reviewAbilitiesOf($jefe, $this->rooms))->toBe(reviewAbilities(true, true, true, true, true, true, true));
});

it('un jefe de proyecto docente con alcance en un campus no corrige ni valida los salones', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create();

    expect(reviewAbilitiesOf($jpd, $this->rooms))->toBe(reviewAbilities(false, false, false, false, false, false, false));
});

it('niega todo a un admin desactivado', function () {
    $admin = User::factory()->admin()->create(['is_active' => false]);

    expect(reviewAbilitiesOf($admin, $this->general))->toBe(reviewAbilities(false, false, false, false, false, false, false));
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
    expect(reviewAbilitiesOf($jefe, $this->general))->toBe(reviewAbilities(true, true, true, true, true, true, true));

    actingAsRole($jefe, Role::Coordinador);
    expect(reviewAbilitiesOf($jefe, $this->general))->toBe(reviewAbilities(false, false, false, false, false, false, false));

    actingAsRole($subdireccion, Role::Subdireccion);
    expect(reviewAbilitiesOf($subdireccion, $this->general))->toBe(reviewAbilities(true, false, false, false, false, true, false))
        ->and($subdireccion->can('viewProgress', CatalogReview::class))->toBeTrue()
        ->and($subdireccion->can('remind', CatalogReview::class))->toBeTrue();

    actingAsRole($subdireccion, Role::Docente);
    expect(reviewAbilitiesOf($subdireccion, $this->general))->toBe(reviewAbilities(false, false, false, false, false, false, false))
        ->and($subdireccion->can('viewProgress', CatalogReview::class))->toBeFalse()
        ->and($subdireccion->can('remind', CatalogReview::class))->toBeFalse();
});
