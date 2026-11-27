<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/*
| Integridad de departamentos, carreras y carrera-campus (tarea 1.7, docs/dominio/modelo-de-datos.md, "Academic"). Las
| claves naturales son únicas también contra un registro borrado (reglas-de-negocio.md, "Reglas de datos"), y nada se
| borra físicamente: todo lleva borrado lógico (confirmado el 29 de octubre de 2026). Las jefaturas son nombramientos
| desde 3.P8 (AZ-S14): sus reglas se prueban en RoleAssignmentsTableTest y DepartmentHeadsMergeMigrationTest.
*/

describe('claves naturales', function () {
    it('la clave de un departamento es única, también contra uno borrado', function () {
        Department::factory()->create(['code' => 'SIS'])->delete();

        expect(fn () => Department::factory()->create(['code' => 'SIS']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('la clave de una carrera es única, también contra una borrada', function () {
        Career::factory()->create(['code' => 'ISIC'])->delete();

        expect(fn () => Career::factory()->create(['code' => 'ISIC']))->toThrow(UniqueConstraintViolationException::class);
    });

    it('una carrera se ofrece una sola vez en cada campus, aunque la anterior se haya quitado', function () {
        $career = Career::factory()->create();
        $campus = Campus::factory()->create();
        CareerCampus::factory()->for($career)->for($campus)->create()->delete();

        expect(fn () => CareerCampus::factory()->for($career)->for($campus)->create())->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('llaves foráneas', function () {
    it('una carrera necesita un departamento que exista', function () {
        expect(fn () => Career::factory()->create(['department_id' => (string) Str::uuid7()]))->toThrow(QueryException::class, 'foreign key');
    });
});
