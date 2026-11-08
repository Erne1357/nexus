<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Models\ChangeRequest;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| ChangeRequestPolicy (tarea 3.11), reglas-de-negocio.md, "Solicitudes de cambio". Decisión 2 (confirmado el 7 de
| noviembre de 2026): el horario lo aplica el coordinador de la carrera dueña; el salón, el jefe del departamento.
*/

/**
 * [crear, ver, decidir, aplicar] de `$user` sobre el grupo y la solicitud.
 *
 * @return list<bool>
 */
function changeAbilities(User $user, array $s, ChangeRequest $request): array
{
    return [
        $user->can('create', [ChangeRequest::class, $s['group']]),
        $user->can('view', $request),
        $user->can('decide', $request),
        $user->can('apply', $request),
    ];
}

it('el docente del grupo pide y ve lo suyo, no decide ni aplica', function () {
    $s = changeScenario();
    $request = requestFor($s['group'], 'approved', ['requested_by' => $s['teacherUser']->id, 'teacher_id' => $s['teacher']->id]);

    expect(changeAbilities($s['teacherUser'], $s, $request))->toBe([true, true, false, false]);
});

it('otro docente, aunque tenga el puesto, no pide ni ve', function () {
    $s = changeScenario();
    $request = requestFor($s['group'], 'approved');
    $otro = User::factory()->withRole(Role::Docente)->create();
    Teacher::factory()->create(['user_id' => $otro->id]);

    expect(changeAbilities($otro, $s, $request))->toBe([false, false, false, false]);
});

it('el docente al que ya le quitaron el grupo no puede pedir', function () {
    $s = changeScenario();
    $s['group']->update(['teacher_id' => Teacher::factory()->create()->id]);

    expect($s['teacherUser']->can('create', [ChangeRequest::class, $s['group']->fresh()]))->toBeFalse();
});

it('el jefe del departamento ve, decide y aplica los cambios de salón, no los de horario', function () {
    $s = changeScenario();

    expect(changeAbilities($s['jefe'], $s, requestFor($s['group'], 'approved', ['kind' => 'room'])))->toBe([false, true, true, true])
        ->and(changeAbilities($s['jefe'], $s, requestFor($s['group'], 'approved', ['kind' => 'schedule'])))->toBe([false, true, true, false]);
});

it('el coordinador de la carrera dueña pide y aplica los de horario; los de salón ni los ve ni los aplica; no decide', function () {
    $s = changeScenario();

    expect(changeAbilities($s['coordinador'], $s, requestFor($s['group'], 'approved', ['kind' => 'schedule'])))->toBe([true, true, false, true])
        ->and(changeAbilities($s['coordinador'], $s, requestFor($s['group'], 'approved', ['kind' => 'room'])))->toBe([true, false, false, false]);
});

it('el jefe de proyecto docente y la subdirección solo ven', function (Role $role, array $scope) {
    $s = changeScenario();
    $user = User::factory()->withRole($role, array_map(fn ($v) => $v instanceof Closure ? $v($s) : $v, $scope))->create();

    expect(changeAbilities($user, $s, requestFor($s['group'], 'approved')))->toBe([false, true, false, false]);
})->with([
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, ['department_id' => fn ($s) => $s['group']->department_id, 'campus_id' => fn ($s) => $s['group']->campus_id]],
    'subdirección' => [Role::Subdireccion, []],
]);

it('niega todo al jefe de otro departamento, al coordinador de otra carrera y al de otro campus', function () {
    $s = changeScenario();
    $request = requestFor($s['group'], 'approved', ['kind' => 'room']);
    $schedule = requestFor($s['group'], 'approved', ['kind' => 'schedule']);
    $jefeOtro = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create();
    $coordOtraCarrera = User::factory()->withRole(Role::Coordinador, ['campus_id' => $s['group']->campus_id, 'career_id' => Career::factory()->create()->id])->create();
    $coordOtroCampus = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => $s['group']->owner_career_id])->create();

    expect(changeAbilities($jefeOtro, $s, $request))->toBe([false, false, false, false])
        ->and(changeAbilities($coordOtraCarrera, $s, $schedule))->toBe([false, false, false, false])
        ->and(changeAbilities($coordOtroCampus, $s, $schedule))->toBe([false, false, false, false]);
});

it('el admin lo puede todo', function () {
    $s = changeScenario();
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect(changeAbilities($admin, $s, requestFor($s['group'], 'approved')))->toBe([true, true, true, true]);
});
