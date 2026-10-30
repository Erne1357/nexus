<?php

use App\Modules\Academic\Actions\DeleteDepartment;
use App\Modules\Academic\Models\Department;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Validation\ValidationException;

/*
| Borrar un departamento se veta si tiene docentes adscritos (S10, supuesto por confirmar), con
| DepartmentDeleting.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
});

function departmentDeletionReasons(Department $department): ?array
{
    try {
        app(DeleteDepartment::class)->handle($department);
    } catch (ValidationException $exception) {
        return $exception->errors()['department'];
    }

    return null;
}

it('rechaza borrar un departamento con un docente adscrito', function () {
    Teacher::factory()->adscribedTo($this->department)->create();

    expect(departmentDeletionReasons($this->department))->toBe(['Tiene 1 docente adscrito.'])
        ->and(Department::find($this->department->id))->not->toBeNull();
});

it('usa el plural con varios docentes y cuenta una vez al que también está en otro departamento', function () {
    Teacher::factory()->count(2)->adscribedTo($this->department)->create();
    Teacher::factory()->adscribedTo(Department::factory()->create())->adscribedTo($this->department, false)->create();

    expect(departmentDeletionReasons($this->department))->toBe(['Tiene 3 docentes adscritos.']);
});

it('con la adscripción borrada sí se borra', function () {
    $teacher = Teacher::factory()->adscribedTo($this->department)->create();
    $teacher->adscriptions()->get()->each->delete();

    expect(departmentDeletionReasons($this->department))->toBeNull()
        ->and(Department::find($this->department->id))->toBeNull();
});

it('un departamento sin docentes se borra', function () {
    Teacher::factory()->adscribedTo(Department::factory()->create())->create();

    expect(departmentDeletionReasons($this->department))->toBeNull();
});

it('un docente borrado cuya adscripción sigue en pie no bloquea', function () {
    Teacher::factory()->adscribedTo($this->department)->create()->delete();

    expect(departmentDeletionReasons($this->department))->toBeNull();
});
