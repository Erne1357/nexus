<?php

use App\Modules\Academic\Actions\DeleteDepartment;
use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Academic\Models\Department;
use App\Modules\Facilities\Models\Room;
use Illuminate\Validation\ValidationException;

/*
| S10 (supuesto por confirmar): Facilities veta borrar un departamento que es dueño de salones.
*/

function deletionReasons(Department $department): array
{
    try {
        app(DeleteDepartment::class)->handle($department);
    } catch (ValidationException $e) {
        return $e->errors()['department'];
    }

    return [];
}

it('veta borrar un departamento dueño de dos salones: «Es dueño de 2 salones.»', function () {
    $department = Department::factory()->create();
    Room::factory()->count(2)->ownedBy($department)->create();

    expect(deletionReasons($department))->toBe(['Es dueño de 2 salones.'])
        ->and(Department::find($department->id))->not->toBeNull();
});

it('dice «Es dueño de 1 salón.» en singular', function () {
    $department = Department::factory()->create();
    Room::factory()->ownedBy($department)->inactive()->create();

    expect(deletionReasons($department))->toBe(['Es dueño de 1 salón.']);
});

it('no cuenta salones borrados ni de otro departamento, y deja borrar', function () {
    $department = Department::factory()->create();
    Room::factory()->ownedBy($department)->create()->delete();
    Room::factory()->ownedBy(Department::factory()->create())->create();

    expect(deletionReasons($department))->toBe([])
        ->and(Department::find($department->id))->toBeNull();
});

it('el listener responde al evento DepartmentDeleting', function () {
    $department = Department::factory()->create();
    Room::factory()->ownedBy($department)->create();

    $event = new DepartmentDeleting($department);
    event($event);

    expect($event->blocked())->toBeTrue()->and($event->reasons())->toBe(['Es dueño de 1 salón.']);
});
