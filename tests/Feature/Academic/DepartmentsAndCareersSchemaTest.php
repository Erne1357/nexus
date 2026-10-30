<?php

use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Integridad de departamentos, jefes, carreras y carrera-campus (tarea 1.7, docs/dominio/modelo-de-datos.md,
| "Academic"). Las claves naturales son únicas también contra un registro borrado (reglas-de-negocio.md,
| "Reglas de datos"), y nada se borra físicamente: todo lleva borrado lógico (confirmado el 29 de octubre
| de 2026). Un departamento tiene un solo jefe vigente para todo el Tec, sin campus (confirmado el 30 de
| octubre de 2026). De jefe de proyecto docente puede haber varios a la vez (confirmado el 29 de octubre de
| 2026), cada uno en un campus (supuesto por confirmar).
*/

/**
 * Un jefe del departamento (sin campus) o un jefe de proyecto docente en `$campus`, con la vigencia dada.
 */
function departmentHeadFor(Department $department, ?Campus $campus, string $from, ?string $to = null, DepartmentHeadKind $kind = DepartmentHeadKind::Jefe): DepartmentHead
{
    return DepartmentHead::factory()->for($department)->create([
        'campus_id' => $campus?->id,
        'kind' => $kind,
        'valid_from' => $from,
        'valid_to' => $to,
    ]);
}

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

    it('un jefe de proyecto docente necesita un departamento, un campus y un usuario que existan', function (string $column) {
        expect(fn () => DepartmentHead::factory()->projectLead()->create([$column => (string) Str::uuid7()]))->toThrow(QueryException::class, 'foreign key');
    })->with(['department_id', 'campus_id', 'user_id']);
});

describe('jefes de departamento', function () {
    beforeEach(function () {
        $this->department = Department::factory()->create();
        $this->c1 = Campus::factory()->create(['code' => 'C1']);
        $this->c2 = Campus::factory()->create(['code' => 'C2']);
    });

    it('el jefe es de todo el Tec: sin campus se acepta y con campus se rechaza', function () {
        $head = departmentHeadFor($this->department, null, '2026-01-01');

        expect($head->fresh()->campus_id)->toBeNull()
            ->and(fn () => departmentHeadFor(Department::factory()->create(), $this->c2, '2026-01-01'))
            ->toThrow(QueryException::class, 'department_heads_campus_check');
    });

    it('el jefe de proyecto docente es de un campus: sin campus se rechaza', function () {
        departmentHeadFor($this->department, $this->c2, '2026-01-01', kind: DepartmentHeadKind::JefeProyectoDocente);

        expect(fn () => departmentHeadFor($this->department, null, '2026-01-01', kind: DepartmentHeadKind::JefeProyectoDocente))
            ->toThrow(QueryException::class, 'department_heads_campus_check');
    });

    it('un departamento tiene un solo jefe vigente: dos periodos que se traslapan se rechazan', function (string $from, ?string $to) {
        departmentHeadFor($this->department, null, '2026-01-01', '2026-12-31');

        expect(fn () => departmentHeadFor($this->department, null, $from, $to))->toThrow(QueryException::class, 'department_heads_one_jefe');
    })->with([
        'adentro' => ['2026-03-01', '2026-06-30'],
        'empieza el último día del anterior' => ['2026-12-31', null],
        'termina el primer día del anterior' => ['2025-07-01', '2026-01-01'],
        'sin fecha de fin y antes' => ['2025-01-01', null],
    ]);

    it('un jefe sin fecha de fin sigue vigente: otro jefe después se traslapa', function () {
        departmentHeadFor($this->department, null, '2026-01-01');

        expect(fn () => departmentHeadFor($this->department, null, '2030-01-01'))->toThrow(QueryException::class, 'department_heads_one_jefe');
    });

    it('un relevo que empieza el día después de que termina el anterior se acepta', function () {
        departmentHeadFor($this->department, null, '2026-01-01', '2026-12-31');
        departmentHeadFor($this->department, null, '2027-01-01');

        expect(DepartmentHead::count())->toBe(2);
    });

    it('dos jefes vigentes del mismo departamento se rechazan: ya no hay uno por campus', function () {
        DepartmentHead::factory()->for($this->department)->create(['valid_from' => '2026-01-01']);

        expect(fn () => DepartmentHead::factory()->for($this->department)->create(['valid_from' => '2026-01-01']))
            ->toThrow(QueryException::class, 'department_heads_one_jefe');
    });

    it('cada departamento tiene su propio jefe', function () {
        departmentHeadFor($this->department, null, '2026-01-01');
        departmentHeadFor(Department::factory()->create(), null, '2026-01-01');

        expect(DepartmentHead::count())->toBe(2);
    });

    it('puede haber dos jefes de proyecto docente vigentes a la vez en el mismo departamento y campus, además del jefe', function () {
        departmentHeadFor($this->department, null, '2026-01-01');
        departmentHeadFor($this->department, $this->c2, '2026-01-01', kind: DepartmentHeadKind::JefeProyectoDocente);
        departmentHeadFor($this->department, $this->c2, '2026-02-01', kind: DepartmentHeadKind::JefeProyectoDocente);

        expect(DepartmentHead::count())->toBe(3);
    });

    it('un jefe borrado ya no ocupa su periodo', function () {
        departmentHeadFor($this->department, null, '2026-01-01')->delete();
        departmentHeadFor($this->department, null, '2026-01-01');

        expect(DepartmentHead::withTrashed()->count())->toBe(2);
    });

    it('la misma persona no es dos veces jefe de proyecto docente del departamento en un campus con periodos que se traslapan', function () {
        $head = departmentHeadFor($this->department, $this->c2, '2026-01-01', kind: DepartmentHeadKind::JefeProyectoDocente);

        expect(fn () => DepartmentHead::factory()->projectLead()->for($this->department)->for($this->c2)->for($head->user)->create([
            'valid_from' => '2026-06-01',
        ]))->toThrow(QueryException::class, 'department_heads_no_duplicate');
    });

    it('la misma persona sí puede ser jefe de proyecto docente del departamento en otro campus a la vez', function () {
        $head = departmentHeadFor($this->department, $this->c2, '2026-01-01', kind: DepartmentHeadKind::JefeProyectoDocente);

        DepartmentHead::factory()->projectLead()->for($this->department)->for($this->c1)->for($head->user)->create(['valid_from' => '2026-06-01']);

        expect(DepartmentHead::count())->toBe(2);
    });

    it('solo acepta los tipos de jefe conocidos: la regla de un solo jefe depende del tipo', function () {
        $head = departmentHeadFor($this->department, null, '2026-01-01');

        expect(fn () => DB::table('department_heads')->insert([
            'department_id' => $this->department->id,
            'campus_id' => $this->c2->id,
            'user_id' => $head->user_id,
            'kind' => 'JEFE',
            'valid_from' => '2027-01-01',
        ]))->toThrow(QueryException::class, 'department_heads_kind_check');
    });

    it('la vigencia no termina antes de empezar, y puede ser de un solo día', function () {
        departmentHeadFor($this->department, null, '2026-01-01', '2026-01-01');

        expect(fn () => departmentHeadFor($this->department, null, '2026-02-01', '2026-01-31'))->toThrow(QueryException::class, 'department_heads_validity_check');
    });
});
