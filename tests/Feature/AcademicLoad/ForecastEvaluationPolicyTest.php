<?php

use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;

/*
| ForecastEvaluationPolicy (DM-24; C3, confirmado el 16 de noviembre de 2026): el reporte de precisión de la
| proyección y lanzar una evaluación son de Subdirección y del admin.
*/

it('DM-24: Subdirección y el admin ven el reporte y lanzan evaluaciones', function () {
    $subdireccion = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
    $admin = User::factory()->admin()->create();

    foreach ([$subdireccion, $admin] as $user) {
        expect($user->can('viewAny', ForecastEvaluation::class))->toBeTrue()
            ->and($user->can('create', ForecastEvaluation::class))->toBeTrue();
    }
});

it('DM-24: ni el jefe, ni el coordinador, ni el jefe de proyecto docente ven el reporte ni lanzan evaluaciones', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, ['campus_id' => Campus::factory()->create()->id, ...$scope])->create();

    expect($user->can('viewAny', ForecastEvaluation::class))->toBeFalse()
        ->and($user->can('create', ForecastEvaluation::class))->toBeFalse();
})->with([
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => departmentKey(10)]],
    'coordinador' => [Role::Coordinador, fn () => ['career_id' => careerKey(20)]],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn () => ['department_id' => departmentKey(10)]],
]);
