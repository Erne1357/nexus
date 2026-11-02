<?php

use App\Modules\Academic\Actions\DeleteDepartment;
use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use Illuminate\Validation\ValidationException;

/*
| S-A8 (supuesto por confirmar): AcademicLoad veta borrar un departamento que tiene cargas académicas,
| con DepartmentDeleting.
*/

function departmentWithLoadsDeletionReasons(Department $department): array
{
    try {
        app(DeleteDepartment::class)->handle($department);
    } catch (ValidationException $exception) {
        return $exception->errors()['department'];
    }

    return [];
}

it('veta borrar un departamento con dos cargas: «Tiene 2 cargas académicas.»', function () {
    $department = Department::factory()->create();
    AcademicLoad::factory()->count(2)->for($department)->create();

    expect(departmentWithLoadsDeletionReasons($department))->toBe(['Tiene 2 cargas académicas.'])
        ->and(Department::find($department->id))->not->toBeNull();
});

it('dice «Tiene 1 carga académica.» en singular', function () {
    $department = Department::factory()->create();
    AcademicLoad::factory()->for($department)->create();

    expect(departmentWithLoadsDeletionReasons($department))->toBe(['Tiene 1 carga académica.']);
});

it('no cuenta las cargas de otro departamento, y deja borrar', function () {
    $department = Department::factory()->create();
    AcademicLoad::factory()->create();

    expect(departmentWithLoadsDeletionReasons($department))->toBe([])
        ->and(Department::find($department->id))->toBeNull();
});

it('el listener responde al evento DepartmentDeleting', function () {
    $department = Department::factory()->create();
    AcademicLoad::factory()->for($department)->create();

    $event = new DepartmentDeleting($department);
    event($event);

    expect($event->blocked())->toBeTrue()->and($event->reasons())->toBe(['Tiene 1 carga académica.']);
});
