<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;

/*
| Relaciones y scopes de departamentos y carreras (tarea 1.7). Las relaciones solo apuntan dentro de
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
