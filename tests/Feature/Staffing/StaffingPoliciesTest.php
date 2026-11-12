<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Support\Facades\Gate;

/*
| Policies de Staffing. Según reglas-de-negocio.md, "Aprobaciones y permisos": asignan el docente el jefe de
| departamento y el jefe de proyecto docente (de su departamento y modalidad; con campus, asignan solo en el suyo, AZ-S17); ven el ranking además la Subdirección.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->otherCampus = Campus::factory()->create();
    $this->group = Group::factory()->create([
        'campus_id' => $this->campus->id,
        'subject_id' => fn () => Subject::factory()->create(['department_id' => departmentKey(11)])->id,
    ]);
});

function staffingAbilities(User $user, Group $group): array
{
    $gate = Gate::forUser($user);

    return [
        'assign' => $gate->allows('create', [GroupTeacherAssignment::class, $group]),
        'ranking' => $gate->allows('viewAny', [StaffingRanking::class, $group]),
    ];
}

it('permite según el puesto y su alcance', function (Role $role, Closure $scope, bool $assign, bool $ranking) {
    $user = User::factory()->withRole($role, $scope($this))->create();

    expect(staffingAbilities($user, $this->group))->toBe(['assign' => $assign, 'ranking' => $ranking]);
})->with([
    'jefe del departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(11)], true, true],
    'jefe de otro departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)], false, false],
    'jefe de proyecto docente del departamento y campus' => [Role::JefeProyectoDocente, fn ($t) => ['campus_id' => $t->campus->id, 'department_id' => departmentKey(11)], true, true],
    'jefe de proyecto docente de otro campus de la misma modalidad (AZ-S17: ve el ranking, no asigna)' => [Role::JefeProyectoDocente, fn ($t) => ['campus_id' => $t->otherCampus->id, 'department_id' => departmentKey(11)], false, true],
    'coordinador' => [Role::Coordinador, fn ($t) => ['campus_id' => $t->campus->id, 'career_id' => Career::factory()->create()->id], false, false],
    'subdirección' => [Role::Subdireccion, fn () => [], false, true],
]);

it('al admin le permite asignar y ver el ranking', function () {
    expect(staffingAbilities(User::factory()->admin()->create(), $this->group))->toBe(['assign' => true, 'ranking' => true]);
});
