<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\DepartmentsTemplate;
use App\Modules\Imports\Templates\ImportContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
| Cada departamento académico es también una unidad del organigrama con la misma llave (ADR-0018, AZ-S2):
| tres triggers de PostgreSQL las mantienen iguales, de modo que las nueve tablas con `department_id`
| siguen apuntando a `departments` sin cambios.
*/

function unitOf(Department $department): ?OrgUnit
{
    return OrgUnit::withTrashed()->find($department->id);
}

it('crear un departamento crea su unidad con el mismo id bajo Subdirección Académica', function () {
    $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);

    $unit = unitOf($department);

    expect($unit)->not->toBeNull()
        ->kind->toBe(OrgUnitKind::DepartamentoAcademico)
        ->code->toBe('SC')
        ->name->toBe('Sistemas y Computación')
        ->is_active->toBeTrue()
        ->parent_id->toBe(OrgUnit::where('code', 'SUB-ACADEMICA')->value('id'));
});

it('cambiar clave, nombre o estado, o borrarlo lógicamente, se refleja en su unidad; restaurarlo la restaura', function () {
    $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas']);

    $department->update(['code' => 'SCO', 'name' => 'Sistemas Computacionales', 'is_active' => false]);

    expect(unitOf($department))->code->toBe('SCO')->name->toBe('Sistemas Computacionales')->is_active->toBeFalse();

    $department->delete();

    expect(unitOf($department))->deleted_at->not->toBeNull();

    $department->restore();

    expect(unitOf($department))->deleted_at->toBeNull();
});

it('borrarlo físicamente borra su unidad', function () {
    $department = Department::factory()->create();

    $department->forceDelete();

    expect(OrgUnit::withTrashed()->whereKey($department->id)->exists())->toBeFalse();
});

it('la base no deja un departamento sin unidad', function () {
    $department = Department::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('org_units')->where('id', $department->id)->delete()))
        ->toThrow(QueryException::class, 'departments_id_org_unit_foreign');
});

it('importar departamentos crea sus unidades', function () {
    $context = new ImportContext(User::factory()->admin()->create());
    $template = new DepartmentsTemplate;

    foreach ([['sc', 'Sistemas y Computación'], ['cb', 'Ciencias Básicas']] as [$code, $name]) {
        $template->apply($template->normalize(['code' => $code, 'name' => $name]), $context);
    }

    $departments = Department::all();

    expect($departments)->toHaveCount(2);

    foreach ($departments as $department) {
        expect(unitOf($department))->kind->toBe(OrgUnitKind::DepartamentoAcademico)->code->toBe($department->code);
    }
});

it('la migración da unidad a los departamentos que ya existían, también a los borrados', function () {
    $migration = require base_path('app/Modules/Academic/Database/Migrations/2026_11_03_000002_make_departments_org_units.php');
    $migration->down();

    expect(OrgUnit::where('kind', 'departamento_academico')->count())->toBe(0);

    $alive = DB::table('departments')->insertGetId(['code' => 'SC', 'name' => 'Sistemas', 'is_active' => true]);
    $deleted = DB::table('departments')->insertGetId(['code' => 'IND', 'name' => 'Industrial', 'is_active' => false, 'deleted_at' => now()]);

    $migration->up();

    $parent = OrgUnit::where('code', 'SUB-ACADEMICA')->value('id');

    expect(OrgUnit::withTrashed()->find($alive))->code->toBe('SC')->parent_id->toBe($parent)->deleted_at->toBeNull()
        ->and(OrgUnit::withTrashed()->find($deleted))->code->toBe('IND')->is_active->toBeFalse()->deleted_at->not->toBeNull()
        ->and(OrgUnit::withTrashed()->find($deleted)->kind)->toBe(OrgUnitKind::DepartamentoAcademico);

    $created = Department::factory()->create();
    expect(unitOf($created))->not->toBeNull();
});

it('volver a sembrar departamentos existentes no deja unidades huérfanas', function () {
    $department = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas']);

    foreach (['Sistemas y Computación', 'Sistemas Computacionales'] as $name) {
        Department::upsert([['code' => 'SC', 'name' => $name, 'is_active' => true]], ['code'], ['name', 'is_active']);
    }

    expect(OrgUnit::withTrashed()->where('kind', OrgUnitKind::DepartamentoAcademico)->count())->toBe(Department::withTrashed()->count())
        ->and(unitOf($department))->name->toBe('Sistemas Computacionales');
});
