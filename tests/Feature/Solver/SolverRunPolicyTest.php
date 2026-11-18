<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Models\SolverRun;

/*
| SolverRunPolicy (tarea 6.2; reglas-de-negocio.md, «Aprobaciones y permisos»; decisión del usuario del 17 de noviembre
| de 2026): el jefe de departamento lanza y acepta las corridas de la carga de su departamento; el coordinador lanza
| corridas de su carrera para explorar, sin aceptarlas; Subdirección ve todas. El admin, siempre.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->presencial = modalityOf('PRES');
    $this->departmentRun = SolverRun::factory()->done()->create(['modality_id' => $this->presencial->id, 'department_id' => departmentKey(10)]);
    $this->careerRun = SolverRun::factory()->forCareer()->done()->create(['modality_id' => $this->presencial->id, 'career_id' => careerKey(20)]);
});

function solverUser(Role $role, array $scope = []): User
{
    return User::factory()->withRole($role, ['campus_id' => test()->campus->id, ...$scope])->create();
}

it('cada puesto lanza, ve y acepta según su alcance', function (Role $role, array $scope, array $expected) {
    $user = solverUser($role, $scope);

    expect([
        'lanza depto' => $user->can('launchForDepartment', [SolverRun::class, $this->presencial->id, departmentKey(10)]),
        'lanza carrera' => $user->can('launchForCareer', [SolverRun::class, $this->presencial->id, careerKey(20)]),
        've depto' => $user->can('view', $this->departmentRun),
        've carrera' => $user->can('view', $this->careerRun),
        'acepta depto' => $user->can('apply', $this->departmentRun),
        'acepta carrera' => $user->can('apply', $this->careerRun),
    ])->toBe($expected);
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)],
        ['lanza depto' => true, 'lanza carrera' => false, 've depto' => true, 've carrera' => false, 'acepta depto' => true, 'acepta carrera' => false]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)],
        ['lanza depto' => false, 'lanza carrera' => true, 've depto' => false, 've carrera' => true, 'acepta depto' => false, 'acepta carrera' => false]],
    'subdirección' => [Role::Subdireccion, [],
        ['lanza depto' => false, 'lanza carrera' => false, 've depto' => true, 've carrera' => true, 'acepta depto' => false, 'acepta carrera' => false]],
    'docente' => [Role::Docente, [],
        ['lanza depto' => false, 'lanza carrera' => false, 've depto' => false, 've carrera' => false, 'acepta depto' => false, 'acepta carrera' => false]],
]);

it('niega al jefe de otro departamento y al coordinador de otra carrera', function () {
    $jefe = solverUser(Role::JefeDepartamento, ['department_id' => departmentKey(11)]);
    $coordinador = solverUser(Role::Coordinador, ['career_id' => careerKey(21)]);

    expect($jefe->can('launchForDepartment', [SolverRun::class, $this->presencial->id, departmentKey(10)]))->toBeFalse()
        ->and($jefe->can('view', $this->departmentRun))->toBeFalse()
        ->and($jefe->can('apply', $this->departmentRun))->toBeFalse()
        ->and($coordinador->can('launchForCareer', [SolverRun::class, $this->presencial->id, careerKey(20)]))->toBeFalse()
        ->and($coordinador->can('view', $this->careerRun))->toBeFalse();
});

it('una corrida que no está terminada no se acepta, ni la del propio jefe', function (string $status) {
    $jefe = solverUser(Role::JefeDepartamento, ['department_id' => departmentKey(10)]);
    $this->departmentRun->update(['status' => $status]);

    expect($jefe->can('apply', $this->departmentRun))->toBeFalse();
})->with(['queued', 'running', 'failed', 'applied']);

it('al admin le permite todo sobre las corridas terminadas', function () {
    $admin = User::factory()->admin()->create();

    expect($admin->can('view', $this->careerRun))->toBeTrue()
        ->and($admin->can('launchForDepartment', [SolverRun::class, $this->presencial->id, departmentKey(10)]))->toBeTrue()
        ->and($admin->can('apply', $this->departmentRun))->toBeTrue();
});
