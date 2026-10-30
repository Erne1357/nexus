<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\StudentQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| StudentQuery: el listado de alumnos de un campus (tarea 1.6), por número de control y paginado, con búsqueda
| literal por número de control o nombre y filtros por carrera, plan, semestre y estatus. Lista lo que
| StudentPolicy deja ver, filtrado en SQL: al admin y a la subdirección, todo el campus; al jefe de departamento,
| las carreras de su departamento; al coordinador, su carrera (reglas-de-negocio.md, "Consultar alumnos").
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create();
    $this->department = Department::factory()->create();
    $this->isc = Career::factory()->for($this->department)->create(['code' => 'ISC']);
    $this->iti = Career::factory()->for($this->department)->create(['code' => 'ITI']);
    $this->ind = Career::factory()->create(['code' => 'IND']);
    $this->iscPlan = StudyPlan::factory()->for($this->isc)->create(['code' => 'ISC-2010']);
    $this->itiPlan = StudyPlan::factory()->for($this->iti)->create();
    $this->indPlan = StudyPlan::factory()->for($this->ind)->create();
    $this->students = [
        '21000001' => listedStudent($this->iscPlan, $this->campus, ['control_number' => '21000001', 'name' => 'Ana López', 'current_semester' => 3]),
        '21000002' => listedStudent($this->iscPlan, $this->campus, ['control_number' => '21000002', 'name' => 'Luis Pérez', 'current_semester' => 5, 'status' => StudentStatus::BajaTemporal]),
        '21000003' => listedStudent($this->itiPlan, $this->campus, ['control_number' => '21000003', 'name' => 'María Ruiz', 'current_semester' => 3]),
        '21000004' => listedStudent($this->indPlan, $this->campus, ['control_number' => '21000004', 'name' => 'Óscar Díaz', 'current_semester' => 1, 'status' => null]),
    ];
    listedStudent($this->iscPlan, Campus::factory()->create(), ['control_number' => '21000009']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function listedStudent(StudyPlan $plan, Campus $campus, array $attributes = []): Student
{
    return Student::factory()->for($plan)->create(['campus_id' => $campus->id, ...$attributes]);
}

/**
 * @param  array{career?: string, plan?: string, semester?: int, status?: string}  $filters
 * @return list<string>
 */
function listedControlNumbers(User $user, Campus $campus, array $filters = [], string $search = '', int $perPage = 25): array
{
    test()->actingAs($user);

    return app(StudentQuery::class)->forTable(app(ScopedContext::class), $campus, $filters, $search, $perPage)->pluck('control_number')->all();
}

it('al admin y a la subdirección les lista a todos los alumnos del campus, por número de control', function (Role $role) {
    $user = $role === Role::Admin ? User::factory()->admin()->create() : User::factory()->withRole($role, ['campus_id' => $this->campus->id])->create();

    expect(listedControlNumbers($user, $this->campus))->toBe(['21000001', '21000002', '21000003', '21000004']);
})->with([Role::Admin, Role::Subdireccion]);

it('al jefe de departamento le lista los alumnos de las carreras de su departamento', function () {
    $head = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->department->id])->create();

    expect(listedControlNumbers($head, $this->campus))->toBe(['21000001', '21000002', '21000003']);
});

it('al coordinador le lista solo los alumnos de su carrera', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->iti->id])->create();

    expect(listedControlNumbers($coordinator, $this->campus))->toBe(['21000003']);
});

it('no lista nada a quien no tiene alcance en ese campus, aunque lo tenga en otro', function () {
    $elsewhere = User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create();
    $teacherHead = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->campus->id, 'department_id' => $this->department->id])->create();

    expect(listedControlNumbers($elsewhere, $this->campus))->toBe([])
        ->and(listedControlNumbers($teacherHead, $this->campus))->toBe([]);
});

it('a la subdirección y al jefe de departamento de todo el Tec les lista los alumnos de su alcance en cualquier campus', function () {
    $otherCampus = Campus::query()->whereKeyNot($this->campus->id)->sole();
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->department->id])->create();

    expect(listedControlNumbers($subdireccion, $this->campus))->toBe(['21000001', '21000002', '21000003', '21000004'])
        ->and(listedControlNumbers($head, $this->campus))->toBe(['21000001', '21000002', '21000003'])
        ->and(listedControlNumbers($head, $otherCampus))->toBe(['21000009']);
});

it('con un alcance de todo el Tec no lista nada en un campus desactivado, igual que la policy', function () {
    $otherCampus = Campus::query()->whereKeyNot($this->campus->id)->sole();
    $otherCampus->update(['is_active' => false]);
    $subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    expect(listedControlNumbers($subdireccion, $otherCampus))->toBe([])
        ->and($subdireccion->can('view', Student::query()->where('control_number', '21000009')->sole()))->toBeFalse();
});

it('lo que lista coincide con lo que la policy deja ver, también con alcances que traen columnas de más: :dataset', function (User $user) {
    $listed = listedControlNumbers($user, $this->campus);

    foreach ($this->students as $controlNumber => $student) {
        expect($user->can('view', $student))->toBe(in_array((string) $controlNumber, $listed, true));
    }
})->with([
    'subdirección' => fn () => User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->campus->id])->create(),
    'subdirección con departamento' => fn () => User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->campus->id, 'department_id' => $this->department->id])->create(),
    'jefe de departamento' => fn () => User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->department->id])->create(),
    'jefe de departamento con carrera' => fn () => User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->campus->id, 'department_id' => $this->department->id, 'career_id' => $this->iti->id])->create(),
    'coordinador' => fn () => User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create(),
    'subdirección de todo el Tec' => fn () => User::factory()->withRole(Role::Subdireccion)->create(),
    'jefe de departamento de todo el Tec' => fn () => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->department->id])->create(),
    'docente de todo el Tec' => fn () => User::factory()->withRole(Role::Docente)->create(),
]);

it('filtra por carrera, plan, semestre y estatus, también por los que no tienen estatus', function (array $filters, array $expected) {
    $filters = array_map(fn ($value) => $value instanceof Closure ? $value($this) : $value, $filters);

    expect(listedControlNumbers(User::factory()->admin()->create(), $this->campus, $filters))->toBe($expected);
})->with([
    'carrera' => [['career' => fn ($test) => $test->isc->id], ['21000001', '21000002']],
    'plan' => [['plan' => fn ($test) => $test->itiPlan->id], ['21000003']],
    'semestre' => [['semester' => 3], ['21000001', '21000003']],
    'estatus' => [['status' => 'baja_temporal'], ['21000002']],
    'sin estatus' => [['status' => StudentQuery::WITHOUT_STATUS], ['21000004']],
    'semestre que llega como texto' => [['semester' => '5'], ['21000002']],
]);

it('un filtro que llega alterado del navegador no truena: no encuentra a nadie', function (array $filters) {
    expect(listedControlNumbers(User::factory()->admin()->create(), $this->campus, $filters))->toBe([]);
})->with([
    'carrera que no es uuid' => [['career' => 'abc']],
    'plan que no es uuid' => [['plan' => "1' or '1'='1"]],
    'semestre que no es número' => [['semester' => 'x']],
]);

it('busca por número de control o por nombre, de forma literal y sin distinguir mayúsculas', function () {
    $admin = User::factory()->admin()->create();

    expect(listedControlNumbers($admin, $this->campus, search: '0003'))->toBe(['21000003'])
        ->and(listedControlNumbers($admin, $this->campus, search: 'LÓPEZ'))->toBe(['21000001'])
        ->and(listedControlNumbers($admin, $this->campus, search: '%'))->toBe([]);
});

it('deja fuera a los alumnos borrados y pagina', function () {
    $this->students['21000004']->delete();

    expect(listedControlNumbers(User::factory()->admin()->create(), $this->campus, perPage: 2))->toBe(['21000001', '21000002']);
});

it('ofrece en los filtros solo las carreras y los planes de los alumnos que ve', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
    $this->actingAs($coordinator);

    $options = app(StudentQuery::class)->filterOptions(app(ScopedContext::class), $this->campus);

    expect(array_column($options['careers'], 'name'))->toBe(["ISC · {$this->isc->name}"])
        ->and(array_column($options['plans'], 'name'))->toBe(['ISC-2010'])
        ->and(array_column($options['semesters'], 'id'))->toBe([3, 5]);
});

it('carga al alumno de la ficha por su id; uno que no es uuid o un alumno borrado no existe', function () {
    $student = $this->students['21000001'];
    $deleted = $this->students['21000004'];
    $deleted->delete();

    expect(app(StudentQuery::class)->findOrFail($student->id)->is($student))->toBeTrue()
        ->and(fn () => app(StudentQuery::class)->findOrFail('no-es-un-uuid'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(StudentQuery::class)->findOrFail($deleted->id))->toThrow(ModelNotFoundException::class);
});
