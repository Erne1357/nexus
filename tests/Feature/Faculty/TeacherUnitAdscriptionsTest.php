<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\UnitAdscriptions;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Queries\TeacherUnitAdscriptions;

/*
| Faculty le dice al Organigrama de Core quiénes están adscritos a cada departamento (el contrato UnitAdscriptions):
| el id de un departamento es el de su unidad. Cuentan las adscripciones sin borrar de los docentes activos, sin
| borrar y con usuario.
*/

it('da los usuarios activos adscritos a cada departamento', function () {
    $systems = Department::factory()->create(['code' => 'SC']);
    $basics = Department::factory()->create(['code' => 'CB']);
    $ana = User::factory()->create();
    $beto = User::factory()->create();

    $anaTeacher = Teacher::factory()->adscribedTo($systems)->create(['user_id' => $ana->id]);
    TeacherDepartment::factory()->create(['teacher_id' => $anaTeacher->id, 'department_id' => $basics->id, 'is_primary' => false]);
    $betoTeacher = Teacher::factory()->adscribedTo($systems)->create(['user_id' => $beto->id]);
    TeacherDepartment::factory()->create(['teacher_id' => $betoTeacher->id, 'department_id' => $basics->id, 'is_primary' => false])->delete();

    Teacher::factory()->inactive()->adscribedTo($systems)->create(['user_id' => User::factory()->create()->id]);
    Teacher::factory()->adscribedTo($basics)->create(['user_id' => User::factory()->create()->id])->delete();
    Teacher::factory()->adscribedTo($basics)->create();

    $byUnit = app(UnitAdscriptions::class)->userIdsByUnit();
    ksort($byUnit);
    $byUnit = array_map(function (array $users): array {
        sort($users);

        return $users;
    }, $byUnit);

    $expected = [$systems->id => collect([$ana->id, $beto->id])->sort()->values()->all(), $basics->id => [$ana->id]];
    ksort($expected);

    expect(app(UnitAdscriptions::class))->toBeInstanceOf(TeacherUnitAdscriptions::class)
        ->and($byUnit)->toBe($expected);
});
