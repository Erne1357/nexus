<?php

use App\Modules\Academic\Actions\DeleteDepartment;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Validation\ValidationException;

/*
| Borrar un departamento se veta si tiene docentes adscritos (S10, supuesto por confirmar), con
| DepartmentDeleting.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
});

function deleteReasons(Department $department): ?array
{
    try {
        app(DeleteDepartment::class)->handle($department);
    } catch (ValidationException $exception) {
        return $exception->errors()['department'];
    }

    return null;
}

it('rechaza borrar un departamento con un docente adscrito', function () {
    Teacher::factory()->adscribedTo($this->department, Campus::factory()->create())->create();

    expect(deleteReasons($this->department))->toBe(['Tiene 1 docente adscrito.'])
        ->and(Department::find($this->department->id))->not->toBeNull();
});

it('usa el plural con varios docentes y no cuenta dos veces al que está en dos campus', function () {
    $campus = Campus::factory()->create();
    Teacher::factory()->count(2)->adscribedTo($this->department, $campus)->create();
    $both = Teacher::factory()->adscribedTo($this->department, $campus)->create();
    TeacherDepartment::factory()->for($both)->for($this->department)->for(Campus::factory())->create(['is_primary' => false]);

    expect(deleteReasons($this->department))->toBe(['Tiene 3 docentes adscritos.']);
});

it('con la adscripción borrada sí se borra', function () {
    $teacher = Teacher::factory()->adscribedTo($this->department, Campus::factory()->create())->create();
    $teacher->adscriptions()->get()->each->delete();

    expect(deleteReasons($this->department))->toBeNull()
        ->and(Department::find($this->department->id))->toBeNull();
});

it('un departamento sin docentes se borra', function () {
    Teacher::factory()->adscribedTo(Department::factory()->create(), Campus::factory()->create())->create();

    expect(deleteReasons($this->department))->toBeNull();
});
