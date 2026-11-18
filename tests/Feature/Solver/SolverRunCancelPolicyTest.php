<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Models\SolverRun;

/*
| SolverRunPolicy::cancel y ::discard (tarea 6.8, revisión del Riel A en #587): cancela o descarta una corrida quien la
| lanzó o quien puede lanzar ese alcance. El jefe, las de la carga de su departamento; el coordinador, las de su
| carrera; Subdirección solo las ve. El estado (en curso para cancelar, terminada para descartar) lo revisa la Action.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->presencial = modalityOf('PRES');
    $this->departmentRun = SolverRun::factory()->create(['modality_id' => $this->presencial->id, 'department_id' => departmentKey(30)]);
    $this->careerRun = SolverRun::factory()->forCareer()->create(['modality_id' => $this->presencial->id, 'career_id' => careerKey(40)]);
});

function cancelPolicyUser(Role $role, array $scope = []): User
{
    return User::factory()->withRole($role, ['campus_id' => test()->campus->id, ...$scope])->create();
}

it('cancela y descarta quien puede lanzar ese alcance', function (Role $role, array $scope, array $expected) {
    $user = cancelPolicyUser($role, $scope);

    expect([
        'cancela depto' => $user->can('cancel', $this->departmentRun),
        'cancela carrera' => $user->can('cancel', $this->careerRun),
        'descarta depto' => $user->can('discard', $this->departmentRun),
        'descarta carrera' => $user->can('discard', $this->careerRun),
    ])->toBe($expected);
})->with([
    'jefe del departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(30)],
        ['cancela depto' => true, 'cancela carrera' => false, 'descarta depto' => true, 'descarta carrera' => false]],
    'coordinador de la carrera' => [Role::Coordinador, fn () => ['career_id' => careerKey(40)],
        ['cancela depto' => false, 'cancela carrera' => true, 'descarta depto' => false, 'descarta carrera' => true]],
    'jefe de otro departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(31)],
        ['cancela depto' => false, 'cancela carrera' => false, 'descarta depto' => false, 'descarta carrera' => false]],
    'coordinador de otra carrera' => [Role::Coordinador, fn () => ['career_id' => careerKey(41)],
        ['cancela depto' => false, 'cancela carrera' => false, 'descarta depto' => false, 'descarta carrera' => false]],
    'subdirección' => [Role::Subdireccion, [],
        ['cancela depto' => false, 'cancela carrera' => false, 'descarta depto' => false, 'descarta carrera' => false]],
]);

it('quien lanzó la corrida la cancela y la descarta aunque ya no pueda lanzar', function () {
    $author = User::factory()->create();
    $run = SolverRun::factory()->create(['created_by' => $author->id]);

    expect($author->can('cancel', $run))->toBeTrue()
        ->and($author->can('discard', $run))->toBeTrue()
        ->and($author->can('cancel', $this->departmentRun))->toBeFalse();
});

it('al admin le permite cancelar y descartar cualquiera', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('cancel', $this->departmentRun))->toBeTrue()
        ->and($admin->can('discard', $this->careerRun))->toBeTrue();
});
