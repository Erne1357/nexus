<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;

/*
| GroupPolicy. Según reglas-de-negocio.md, "Aprobaciones y permisos": ven el grupo quienes ven la carga y el
| coordinador de su carrera dueña en su campus (confirmado el 7 de noviembre de 2026); lo gestionan y acomodan sus
| sesiones el coordinador de su carrera dueña y el jefe de su departamento; asignan salón y reparten cupos el jefe
| de su departamento. El admin, siempre.
*/

const GROUP_ABILITIES = ['view', 'manage', 'editSessions', 'assignRoom', 'manageQuotas'];

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->otherCampus = Campus::factory()->create();
    $career = Career::factory()->create(['department_id' => departmentKey(10)]);
    $this->group = Group::factory()->create([
        'campus_id' => $this->campus->id,
        'owner_career_id' => $career->id,
        'subject_id' => fn () => Subject::factory()->create(['department_id' => departmentKey(11)])->id,
    ]);
});

/**
 * Las abilities del grupo que el usuario tiene, en el orden de GROUP_ABILITIES.
 *
 * @return list<string>
 */
function groupAbilitiesOf(User $user, Group $group): array
{
    return array_values(array_filter(GROUP_ABILITIES, fn (string $ability): bool => $user->can($ability, $group)));
}

it('permite según el puesto y su alcance en el propio grupo', function (Role $role, Closure $scope, array $abilities) {
    $user = User::factory()->withRole($role, $scope($this))->create();

    expect(groupAbilitiesOf($user, $this->group))->toBe($abilities);
})->with([
    'subdirección' => [Role::Subdireccion, fn () => [], ['view']],
    'jefe del departamento de la materia' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(11)], GROUP_ABILITIES],
    'jefe de proyecto docente del departamento en el campus' => [Role::JefeProyectoDocente, fn ($test) => ['campus_id' => $test->campus->id, 'department_id' => departmentKey(11)], ['view']],
    'coordinador de la carrera dueña en el campus' => [Role::Coordinador, fn ($test) => ['campus_id' => $test->campus->id, 'career_id' => $test->group->owner_career_id], ['view', 'manage', 'editSessions']],
    'docente' => [Role::Docente, fn () => [], []],
]);

it('al admin le permite todo', function () {
    expect(groupAbilitiesOf(User::factory()->admin()->create(), $this->group))->toBe(GROUP_ABILITIES);
});

it('el grupo es del departamento de la materia, no del de la carrera dueña', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();

    expect(groupAbilitiesOf($jefe, $this->group))->toBe([]);
});

it('AZ-S17: niega al coordinador de otra carrera; al de la carrera dueña en otro campus de la modalidad solo le deja ver', function () {
    $otherCareer = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create();
    $otherCampus = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->otherCampus->id, 'career_id' => $this->group->owner_career_id])->create();

    expect(groupAbilitiesOf($otherCareer, $this->group))->toBe([])
        ->and(groupAbilitiesOf($otherCampus, $this->group))->toBe(['view']);
});

it('AZ-S17: el jefe de proyecto docente de otro campus de la modalidad solo ve el grupo', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->otherCampus->id, 'department_id' => departmentKey(11)])->create();

    expect(groupAbilitiesOf($jpd, $this->group))->toBe(['view']);
});

it('niega todo a un admin desactivado', function () {
    expect(groupAbilitiesOf(User::factory()->admin()->create(['is_active' => false]), $this->group))->toBe([]);
});

describe('M16: grupos de un campus inactivo', function () {
    beforeEach(function () {
        $this->campus->update(['is_active' => false]);
        $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();
    });

    it('M16: el jefe del departamento lo cambia a otro campus activo o lo cancela, y nada más', function () {
        $inactive = Campus::factory()->create(['is_active' => false]);

        expect($this->jefe->can('moveToCampus', [$this->group, $this->otherCampus]))->toBeTrue()
            ->and($this->jefe->can('moveToCampus', [$this->group, $inactive]))->toBeFalse()
            ->and($this->jefe->can('cancel', $this->group))->toBeTrue()
            ->and(groupAbilitiesOf($this->jefe, $this->group))->toBe(['view']);
    });

    it('M16: el coordinador de la carrera dueña no lo cambia ni lo cancela, tenga o no campus', function (Closure $scope) {
        $coordinator = User::factory()->withRole(Role::Coordinador, ['career_id' => $this->group->owner_career_id, ...$scope($this)])->create();

        expect($coordinator->can('moveToCampus', [$this->group, $this->otherCampus]))->toBeFalse()
            ->and($coordinator->can('cancel', $this->group))->toBeFalse()
            ->and(groupAbilitiesOf($coordinator, $this->group))->toBe(['view']);
    })->with([
        'sin campus' => [fn () => []],
        'del otro campus' => [fn ($test) => ['campus_id' => $test->otherCampus->id]],
    ]);

    it('M16: el jefe de otro departamento no lo cambia ni lo cancela', function () {
        $other = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();

        expect($other->can('moveToCampus', [$this->group, $this->otherCampus]))->toBeFalse()
            ->and($other->can('cancel', $this->group))->toBeFalse();
    });
});

it('M16: con el campus del grupo activo, cancelarlo es de quien lo gestiona en su campus', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(11)])->create();
    $here = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->group->owner_career_id])->create();
    $elsewhere = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->otherCampus->id, 'career_id' => $this->group->owner_career_id])->create();

    expect($jefe->can('cancel', $this->group))->toBeTrue()
        ->and($here->can('cancel', $this->group))->toBeTrue()
        ->and($elsewhere->can('cancel', $this->group))->toBeFalse();
});
