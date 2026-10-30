<?php

use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;

/*
| Relaciones y scopes de departamentos, jefes y carreras (tarea 1.7). Las relaciones solo apuntan dentro de
| Academic o hacia Core: Campus y User no saben de Academic (tests/Arch/ModulesTest.php).
*/

it('una carrera pertenece al departamento que la coordina, y el departamento lista sus carreras', function () {
    $department = Department::factory()->create();
    $career = Career::factory()->for($department)->create();

    expect($career->department->is($department))->toBeTrue()
        ->and($department->careers->modelKeys())->toBe([$career->id]);
});

it('una carrera lista los campus donde se ofrece, con su estado, sin los que se quitaron', function () {
    $career = Career::factory()->create();
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $c3 = Campus::factory()->create(['code' => 'C3']);
    CareerCampus::factory()->for($career)->for($c1)->create();
    CareerCampus::factory()->for($career)->for($c2)->inactive()->create();
    CareerCampus::factory()->for($career)->for($c3)->create()->delete();

    $campuses = $career->campuses()->orderBy('code')->get();

    expect($campuses->pluck('code')->all())->toBe(['C1', 'C2'])
        ->and($campuses->pluck('pivot.is_active')->all())->toBe([true, false]);
});

it('un jefe pertenece a su departamento y su usuario, sin campus, y el departamento lista sus jefes', function () {
    $head = DepartmentHead::factory()->create();
    $projectLead = DepartmentHead::factory()->projectLead()->for($head->department)->create();

    expect($head->department->heads->modelKeys())->toEqualCanonicalizing([$head->id, $projectLead->id])
        ->and($head->campus)->toBeNull()
        ->and($head->user)->toBeInstanceOf(User::class)
        ->and($head->kind)->toBe(DepartmentHeadKind::Jefe)
        ->and($projectLead->campus)->toBeInstanceOf(Campus::class)
        ->and($projectLead->kind)->toBe(DepartmentHeadKind::JefeProyectoDocente);
});

it('currentOn da los jefes vigentes en una fecha, con el último día incluido', function () {
    $current = DepartmentHead::factory()->create(['valid_from' => '2026-01-01', 'valid_to' => null]);
    $endsThatDay = DepartmentHead::factory()->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-10-29']);
    DepartmentHead::factory()->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-10-28']);
    DepartmentHead::factory()->create(['valid_from' => '2026-10-30', 'valid_to' => null]);

    $ids = DepartmentHead::query()->currentOn(Carbon::parse('2026-10-29'))->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([$current->id, $endsThatDay->id]);
});

it('currentOn cuenta los días en Ciudad Juárez aunque la fecha llegue en otra zona', function () {
    $head = DepartmentHead::factory()->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-10-29']);

    // La 1 de la mañana del 30 en UTC sigue siendo el 29 en Ciudad Juárez.
    $ids = DepartmentHead::query()->currentOn(Carbon::parse('2026-10-30 01:00', 'UTC'))->pluck('id')->all();

    expect($ids)->toBe([$head->id]);
});
