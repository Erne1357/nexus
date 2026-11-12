<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| Integridad de `academic_loads` (docs/dominio/modelo-de-datos.md, "AcademicLoad"): la unidad de cierre es
| el departamento en un campus (ADR-0008), una carga por periodo, campus y departamento. Nace en borrador,
| en la versión 0, y el estado solo acepta sus ocho valores.
*/

it('nace en borrador y en la versión 0, y conoce su periodo, campus y departamento', function () {
    $campus = Campus::factory()->create();
    $load = AcademicLoad::query()->create([
        'term_id' => Term::factory()->create()->id,
        'campus_id' => $campus->id,
        'modality_id' => $campus->modality_id,
        'department_id' => Department::factory()->create()->id,
    ])->fresh();

    expect($load->status)->toBe(LoadStatus::Draft)
        ->and($load->current_version)->toBe(0)
        ->and($load->term)->toBeInstanceOf(Term::class)
        ->and($load->campus)->toBeInstanceOf(Campus::class)
        ->and($load->department)->toBeInstanceOf(Department::class)
        ->and($load->closed_at)->toBeNull()
        ->and($load->approved_at)->toBeNull()
        ->and($load->published_at)->toBeNull();
});

it('una sola carga por periodo, campus y departamento; cambiando cualquiera de los tres, sí', function () {
    $load = AcademicLoad::factory()->create();

    AcademicLoad::factory()->create($load->only(['term_id', 'campus_id']));
    AcademicLoad::factory()->create($load->only(['term_id', 'department_id']));
    AcademicLoad::factory()->create($load->only(['campus_id', 'department_id']));

    expect(fn () => AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create($load->only(['term_id', 'campus_id', 'department_id'])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('rechaza un estado que no existe', function () {
    $load = AcademicLoad::factory()->create();

    expect(fn () => DB::table('academic_loads')->where('id', $load->id)->update(['status' => 'cancelled']))
        ->toThrow(QueryException::class, 'academic_loads_status_check');
});

it('acepta los ocho estados del enum', function () {
    foreach (LoadStatus::cases() as $status) {
        AcademicLoad::factory()->inStatus($status)->create();
    }

    expect(AcademicLoad::query()->pluck('status')->all())->toEqualCanonicalizing(LoadStatus::cases());
});
