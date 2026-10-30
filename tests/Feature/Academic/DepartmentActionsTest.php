<?php

use App\Modules\Academic\Actions\CreateDepartment;
use App\Modules\Academic\Actions\DeleteDepartment;
use App\Modules\Academic\Actions\UpdateDepartment;
use App\Modules\Academic\Events\DepartmentCreated;
use App\Modules\Academic\Events\DepartmentDeleted;
use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Academic\Events\DepartmentUpdated;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Actions de Departamento. Crear y editar hacen cumplir la clave normalizada y única, también entre
| los borrados; borrar solo si no tiene carreras ni materias sin borrar, deja que otro módulo lo
| vete con DepartmentDeleting y cierra los alcances de sus jefes (confirmado el 29 de octubre de 2026).
*/

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación'])->fresh();
});

function newDepartment(array $overrides = []): Department
{
    return app(CreateDepartment::class)->handle(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica', ...$overrides]);
}

it('da de alta un departamento activo con la clave en mayúsculas y sin espacios', function () {
    $department = newDepartment(['code' => ' e l e ']);

    expect($department->fresh())->code->toBe('ELE')->name->toBe('Eléctrica-Electrónica')->is_active->toBeTrue();
});

it('registra en la bitácora quién dio de alta el departamento y con qué datos', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $department = newDepartment();

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('created')
        ->description->toBe('Dio de alta el departamento ELE')
        ->and($entry->subject->is($department))->toBeTrue()
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['code' => 'ELE', 'name' => 'Eléctrica-Electrónica', 'is_active' => true],
        ]);
});

it('avisa con DepartmentCreated', function () {
    Event::fake([DepartmentCreated::class]);

    $department = newDepartment();

    Event::assertDispatched(DepartmentCreated::class, fn (DepartmentCreated $event): bool => $event->department->is($department));
});

it('rechaza una clave repetida con un error de validación en code, también contra un borrado', function (bool $trashed) {
    if ($trashed) {
        $this->department->delete();
    }

    try {
        newDepartment(['code' => 'sis']);
        $this->fail('Debió rechazar la clave.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['code'][0])->toContain('Ya existe un departamento con la clave SIS');
    }

    expect(Department::withTrashed()->count())->toBe(1);
})->with(['activo' => false, 'borrado' => true]);

it('rechaza una clave que queda vacía al normalizar', function () {
    expect(fn () => newDepartment(['code' => "\u{00A0} "]))->toThrow(ValidationException::class, 'La clave no puede quedar vacía');
});

it('edita el nombre y acepta su propia clave', function () {
    app(UpdateDepartment::class)->handle($this->department, ['code' => 'sis', 'name' => 'Sistemas']);

    expect($this->department->fresh())->code->toBe('SIS')->name->toBe('Sistemas');
});

it('rechaza al editar la clave de otro departamento', function () {
    Department::factory()->create(['code' => 'ELE']);

    expect(fn () => app(UpdateDepartment::class)->handle($this->department, ['code' => 'ele']))
        ->toThrow(ValidationException::class, 'Ya existe un departamento con la clave ELE');
});

it('sin cambios no escribe, no registra ni avisa', function () {
    Event::fake([DepartmentUpdated::class]);
    $updatedAt = $this->department->updated_at;
    $this->travel(1)->minute();

    app(UpdateDepartment::class)->handle($this->department, ['code' => 'sis', 'name' => 'Sistemas y Computación', 'is_active' => true]);

    expect($this->department->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Activity::count())->toBe(0);
    Event::assertNotDispatched(DepartmentUpdated::class);
});

it('desactiva y activa dejando en la bitácora el valor anterior', function () {
    Event::fake([DepartmentUpdated::class]);

    app(UpdateDepartment::class)->handle($this->department, ['is_active' => false]);
    app(UpdateDepartment::class)->handle($this->department, ['is_active' => true]);

    [$off, $on] = Activity::orderBy('id')->get()->all();

    expect($off->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]])
        ->and($on->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => true], 'old' => ['is_active' => false]])
        ->and($off->description)->toBe('Actualizó el departamento SIS');
    Event::assertDispatchedTimes(DepartmentUpdated::class, 2);
});

it('borra sin dependientes: deja deleted_at, bitácora y avisa con DepartmentDeleted', function () {
    Event::fake([DepartmentDeleted::class]);
    $this->actingAs($admin = User::factory()->admin()->create());

    app(DeleteDepartment::class)->handle($this->department);

    $entry = Activity::where('event', 'deleted')->sole();

    expect(Department::find($this->department->id))->toBeNull()
        ->and(Department::withTrashed()->find($this->department->id)->deleted_at)->not->toBeNull()
        ->and($entry->description)->toBe('Borró el departamento SIS')
        ->and($entry->subject_id)->toBe($this->department->id)
        ->and($entry->causer->is($admin))->toBeTrue()
        ->and($entry->attribute_changes['old'])->toBe(['code' => 'SIS', 'name' => 'Sistemas y Computación', 'is_active' => true]);
    Event::assertDispatched(DepartmentDeleted::class);
});

it('rechaza borrar con carreras o materias sin borrar y dice cuántas', function () {
    Career::factory()->count(2)->create(['department_id' => $this->department->id]);
    Subject::factory()->create(['department_id' => $this->department->id]);

    try {
        app(DeleteDepartment::class)->handle($this->department);
        $this->fail('Debió rechazar el borrado.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['department'][0])->toBe('No se puede borrar: tiene 2 carreras y 1 materia.');
    }

    expect(Department::find($this->department->id))->not->toBeNull()
        ->and(Activity::count())->toBe(0);
});

it('no cuenta las carreras ni las materias ya borradas', function () {
    Career::factory()->create(['department_id' => $this->department->id])->delete();
    Subject::factory()->create(['department_id' => $this->department->id])->delete();

    app(DeleteDepartment::class)->handle($this->department);

    expect(Department::find($this->department->id))->toBeNull();
});

it('rechaza borrar con el motivo de un listener que lo veta, sin borrar ni cerrar alcances', function () {
    $campus = Campus::factory()->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $this->department->id])->create();
    Event::listen(DepartmentDeleting::class, fn (DepartmentDeleting $event) => $event->block('Tiene docentes asignados.'));

    try {
        app(DeleteDepartment::class)->handle($this->department);
        $this->fail('Debió rechazar el borrado.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['department'])->toBe(['Tiene docentes asignados.']);
    }

    expect(Department::find($this->department->id))->not->toBeNull()
        ->and($jefe->roleScopes()->sole()->valid_to)->toBeNull();
});

it('al borrar cierra los alcances de sus jefes', function () {
    $campus = Campus::factory()->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $this->department->id])->create();

    app(DeleteDepartment::class)->handle($this->department);

    expect($jefe->roleScopes()->sole()->valid_to->toDateString())->toBe(today()->subDay()->toDateString());
});
