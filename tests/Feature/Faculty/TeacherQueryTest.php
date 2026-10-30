<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Queries\TeacherQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
| TeacherQuery: la tabla de Docentes deja ver lo mismo que TeacherPolicy (`view`), filtrado en SQL con los
| departamentos de los alcances de ScopedContext, sin campus (el docente no es de un campus), y sin preguntarle a la
| policy fila por fila. Busca por número o nombre; filtra por departamento de una adscripción, por tipo de plaza
| vigente y por estado; trae las horas base del periodo activo de toda la página en una consulta.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->ii = Department::factory()->create(['code' => 'II', 'is_active' => false]);
    $this->term = Term::factory()->current()->create(['starts_on' => '2027-01-25', 'ends_on' => '2027-06-11']);

    $this->ana = Teacher::factory()->adscribedTo($this->ii)->adscribedTo($this->sc, primary: false)
        ->create(['employee_number' => '1000001', 'name' => 'Ana Pérez']);
    $this->beto = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000002', 'name' => 'Beto Ruiz']);
    $this->carla = Teacher::factory()->adscribedTo($this->ii)->inactive()->create(['employee_number' => '1000003', 'name' => 'Carla 50%_Díaz']);
    $this->borrado = Teacher::factory()->adscribedTo($this->sc)->create(['employee_number' => '1000005']);
    $this->borrado->delete();
    // Su adscripción a SC se quitó; queda II.
    $this->ex = Teacher::factory()->adscribedTo($this->ii)->create(['employee_number' => '1000006', 'name' => 'Elena Vega']);
    TeacherDepartment::factory()->for($this->ex)->for($this->sc)->create(['is_primary' => false])->delete();
    // Sin departamento.
    $this->sin = Teacher::factory()->create(['employee_number' => '1000007', 'name' => 'Fausto Gil']);
});

/**
 * Los números de empleado de la tabla que ve `$user`, en orden.
 *
 * @param  array<string, mixed>  $filters
 * @return list<string>
 */
function teacherNumbersFor(User $user, array $filters = []): array
{
    test()->actingAs($user);

    return app(TeacherQuery::class)->forTable(app(ScopedContext::class), ...$filters)->pluck('employee_number')->all();
}

it('al admin y a la Subdirección les trae a todos los docentes sin borrar, también sin departamento, activos e inactivos', function () {
    $all = ['1000001', '1000002', '1000003', '1000006', '1000007'];

    expect(teacherNumbersFor(User::factory()->admin()->create()))->toBe($all)
        ->and(teacherNumbersFor(User::factory()->withRole(Role::Subdireccion)->create()))->toBe($all);
});

it('el jefe de SC, de todo el Tec, solo ve a los adscritos a SC, aunque su principal sea otra', function () {
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create();

    expect(teacherNumbersFor($jefe))->toBe(['1000001', '1000002']);
});

it('el jefe de proyecto docente de SC en un campus ve a todos los adscritos a SC: el docente no es de un campus', function () {
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->sc->id])->create();

    expect(teacherNumbersFor($jpd))->toBe(['1000001', '1000002']);
});

it('con varios alcances ve la unión, y al coordinador y al docente no les trae a nadie', function () {
    $both = User::factory()
        ->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])
        ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->ii->id])
        ->create();
    $coordinador = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
    $docente = User::factory()->withRole(Role::Docente)->create();

    expect(teacherNumbersFor($both))->toBe(['1000001', '1000002', '1000003', '1000006'])
        ->and(teacherNumbersFor($coordinador))->toBe([])
        ->and(teacherNumbersFor($docente))->toBe([]);
});

it('lo que lista a cada rol coincide con lo que TeacherPolicy le deja ver', function () {
    $users = [
        User::factory()->admin()->create(),
        User::factory()->withRole(Role::Subdireccion)->create(),
        User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create(),
        User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => $this->ii->id])->create(),
        User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create(),
    ];

    foreach ($users as $user) {
        $allowed = Teacher::query()->orderBy('employee_number')->get()
            ->filter(fn (Teacher $teacher): bool => Gate::forUser($user)->allows('view', $teacher))
            ->pluck('employee_number')->values()->all();

        expect(teacherNumbersFor($user))->toBe($allowed);
    }
});

it('busca por número o por nombre, con %, _ y \\ literales', function (string $search, array $expected) {
    expect(teacherNumbersFor(User::factory()->admin()->create(), ['search' => $search]))->toBe($expected);
})->with([
    'número' => ['000002', ['1000002']],
    'nombre sin mayúsculas' => ['ana pé', ['1000001']],
    'porcentaje literal' => ['50%_', ['1000003']],
    'comodín que no está' => ['%', ['1000003']],
]);

it('filtra por el departamento de una adscripción sin borrar', function () {
    $admin = User::factory()->admin()->create();

    expect(teacherNumbersFor($admin, ['departmentId' => $this->sc->id]))->toBe(['1000001', '1000002'])
        ->and(teacherNumbersFor($admin, ['departmentId' => 'no-es-uuid']))->toBe([]);
});

it('filtra por el tipo de una plaza vigente al inicio del periodo activo', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->beto)->tiempoCompleto()->create(['valid_from' => '2020-01-01', 'valid_to' => '2026-12-31']);
    TeacherPosition::factory()->for($this->sin)->asignatura(12)->create();

    expect(teacherNumbersFor(User::factory()->admin()->create(), ['kind' => PositionKind::TiempoCompleto]))->toBe(['1000001'])
        ->and(teacherNumbersFor(User::factory()->admin()->create(), ['kind' => PositionKind::Asignatura]))->toBe(['1000007']);
});

it('filtra por estado', function () {
    $admin = User::factory()->admin()->create();

    expect(teacherNumbersFor($admin, ['active' => false]))->toBe(['1000003'])
        ->and(teacherNumbersFor($admin, ['active' => true]))->toBe(['1000001', '1000002', '1000006', '1000007']);
});

it('trae las adscripciones sin borrar de cada docente con su departamento, la principal primero', function () {
    $this->actingAs(User::factory()->admin()->create());

    $page = app(TeacherQuery::class)->forTable(app(ScopedContext::class));
    $ana = $page->firstWhere('employee_number', '1000001');

    expect($ana->relationLoaded('adscriptions'))->toBeTrue()
        ->and($ana->adscriptions->map(fn (TeacherDepartment $adscription): string => "{$adscription->department->code}/".(int) $adscription->is_primary)->all())
        ->toBe(['II/1', 'SC/0'])
        ->and($page->firstWhere('employee_number', '1000006')->adscriptions)->toHaveCount(1)
        ->and($page->firstWhere('employee_number', '1000007')->adscriptions)->toHaveCount(0);
});

it('las opciones de departamento marcan los inactivos', function () {
    $this->actingAs(User::factory()->admin()->create());
    $context = app(ScopedContext::class);

    expect(app(TeacherQuery::class)->filterOptions($context)['departments'])->toBe([
        ['id' => $this->ii->id, 'name' => "II · {$this->ii->name} (inactivo)"],
        ['id' => $this->sc->id, 'name' => "SC · {$this->sc->name}"],
    ])
        ->and(array_column(app(TeacherQuery::class)->adscriptionTargets($context), 'name', 'code'))->toMatchArray([
            'II' => "II · {$this->ii->name} (inactivo)",
            'SC' => "SC · {$this->sc->name}",
        ]);
});

it('el jefe da de alta solo en su departamento; la Subdirección, en cualquiera', function () {
    $this->actingAs(User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sc->id])->create());
    $jefeTargets = app(TeacherQuery::class)->adscriptionTargets(app(ScopedContext::class));

    $this->actingAs(User::factory()->withRole(Role::Subdireccion)->create());
    $subdireccionTargets = app(TeacherQuery::class)->adscriptionTargets(app(ScopedContext::class));

    expect(array_column($jefeTargets, 'code'))->toBe(['SC'])
        ->and(array_column($subdireccionTargets, 'code'))->toBe(['II', 'SC']);
});

it('da las horas base del periodo activo de toda la página en una sola consulta', function () {
    TeacherPosition::factory()->for($this->ana)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->ana)->asignatura(5)->create();
    TeacherPosition::factory()->for($this->beto)->expired()->create();
    $this->actingAs(User::factory()->admin()->create());
    $page = app(TeacherQuery::class)->forTable(app(ScopedContext::class));

    DB::enableQueryLog();
    $hours = app(TeacherQuery::class)->baseHoursFor($this->term, $page);

    expect(DB::getQueryLog())->toHaveCount(1)
        ->and($hours)->toEqual([
            $this->ana->id => 45,
            $this->beto->id => 0,
            $this->carla->id => 0,
            $this->ex->id => 0,
            $this->sin->id => 0,
        ])
        // Sin periodo activo no hay horas base que mostrar.
        ->and(app(TeacherQuery::class)->baseHoursFor(null, $page))->toBe([]);
});

it('carga 40 docentes con 3 adscripciones cada uno y sus horas base sin N+1', function () {
    $departments = [$this->sc, $this->ii, Department::factory()->create()];

    Teacher::factory()->count(40)->create()->each(function (Teacher $teacher) use ($departments): void {
        foreach ([0, 1, 2] as $offset) {
            TeacherDepartment::factory()->for($teacher)->create(['department_id' => $departments[$offset]->id, 'is_primary' => $offset === 0]);
        }

        TeacherPosition::factory()->for($teacher)->medioTiempo()->create();
    });

    $this->actingAs(User::factory()->admin()->create());
    $context = app(ScopedContext::class);
    $context->isAdmin();

    DB::enableQueryLog();
    $page = app(TeacherQuery::class)->forTable($context, perPage: 50);
    $hours = app(TeacherQuery::class)->baseHoursFor($this->term, $page);
    $page->each(fn (Teacher $teacher) => $teacher->adscriptions->each(fn (TeacherDepartment $adscription) => $adscription->department->code));

    expect($page)->toHaveCount(45)
        ->and(count($hours))->toBe(45)
        // La página, su cuenta, las adscripciones, sus departamentos y las horas base.
        ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(5);
});

it('findOrFail no trae borrados ni acepta un id que no es uuid; findByEmployeeNumber solo a los no borrados', function () {
    $query = app(TeacherQuery::class);

    expect($query->findOrFail($this->ana->id)->is($this->ana))->toBeTrue()
        ->and(fn () => $query->findOrFail($this->borrado->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findOrFail('no-es-uuid'))->toThrow(ModelNotFoundException::class)
        ->and($query->findByEmployeeNumber('1000002')?->is($this->beto))->toBeTrue()
        ->and($query->findByEmployeeNumber('1000005'))->toBeNull()
        ->and($query->findByEmployeeNumber('9999999'))->toBeNull();
});
