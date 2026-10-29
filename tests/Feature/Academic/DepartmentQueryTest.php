<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Queries\DepartmentQuery;

/*
| DepartmentQuery::options(), las opciones de departamento que comparten los filtros y formularios
| de Carreras y Materias.
*/

it('da los departamentos sin borrar por clave como opciones de x-select, y marca los inactivos', function () {
    $sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $ele = Department::factory()->inactive()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);
    Department::factory()->create(['code' => 'OLD'])->delete();

    expect(app(DepartmentQuery::class)->options())->toBe([
        ['id' => $ele->id, 'name' => 'ELE · Eléctrica-Electrónica (inactivo)'],
        ['id' => $sis->id, 'name' => 'SIS · Sistemas y Computación'],
    ]);
});
