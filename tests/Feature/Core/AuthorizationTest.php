<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Authorization\FakePermission;

/*
| Regla de decisión de la spec 7.1 (ADR-0018) sobre los permisos de prueba: allows(), allowsAny() y constrain().
| Campus C1 y C2 activos, C3 desactivado. Departamentos D1 (con una oficina debajo) y D2 bajo Subdirección
| Académica; D3 movido bajo otra subdirección. Carreras X e Y. "Hoy" es el 3 de noviembre de 2026.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'America/Ciudad_Juarez'));

    $planeacion = OrgUnit::factory()->create(['kind' => OrgUnitKind::Subdireccion]);
    $d3 = departmentKey(12);
    OrgUnit::find($d3)->update(['parent_id' => $planeacion->id]);

    $this->f = [
        'c1' => Campus::factory()->create(['code' => 'C1'])->id,
        'c2' => Campus::factory()->create(['code' => 'C2'])->id,
        'c3' => Campus::factory()->create(['code' => 'C3', 'is_active' => false])->id,
        'd1' => departmentKey(10),
        'office' => OrgUnit::factory()->create(['kind' => OrgUnitKind::Oficina, 'parent_id' => departmentKey(10)])->id,
        'd2' => departmentKey(11),
        'd3' => $d3,
        'academic' => OrgUnit::where('code', 'SUB-ACADEMICA')->value('id'),
        'planeacion' => $planeacion->id,
        'x' => careerKey(20),
        'y' => careerKey(21),
    ];

    $management = [
        FakePermission::ViewCampus, FakePermission::ViewUnit, FakePermission::ViewCareer, FakePermission::ViewCampusUnit,
        FakePermission::ViewCampusCareer, FakePermission::ViewCampusUnitCareer, FakePermission::ViewAny,
    ];

    app()->instance(PermissionRegistry::class, PermissionRegistry::fromDeclarations([FakePermission::class], [
        Role::Subdireccion->value => $management,
        Role::JefeDepartamento->value => [FakePermission::ViewCampus, FakePermission::ViewUnit, FakePermission::ViewCampusUnit, FakePermission::ViewCampusUnitCareer, FakePermission::ViewAny],
        Role::JefeProyectoDocente->value => $management,
        Role::Coordinador->value => [...$management, FakePermission::ViewOwn],
        Role::Docente->value => [FakePermission::ViewOwn],
    ], [FakePermission::ViewCampusCareer]));

    app()->forgetScopedInstances();
});

/**
 * Un usuario de cada forma. «dos puestos»: jefe de D2 y coordinador de Y en C1, más una coordinación de X en C3
 * (desactivado), que no cuenta.
 *
 * @param  array<string, string>  $f
 */
function authorizationUser(string $kind, array $f): User
{
    return match ($kind) {
        'jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $f['d1']])->create(),
        'jefe de proyecto docente' => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $f['c2'], 'department_id' => $f['d2']])->create(),
        'coordinador' => User::factory()->withRole(Role::Coordinador, ['campus_id' => $f['c2'], 'career_id' => $f['x']])->create(),
        'subdirección' => User::factory()->withRole(Role::Subdireccion)->create(),
        'admin' => User::factory()->admin()->create(),
        'docente' => User::factory()->withRole(Role::Docente)->create(),
        'dos puestos' => User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => $f['d2']])
            ->withRole(Role::Coordinador, ['campus_id' => $f['c1'], 'career_id' => $f['y']])
            ->withRole(Role::Coordinador, ['campus_id' => $f['c3'], 'career_id' => $f['x']])
            ->create(),
    };
}

function actorOf(User $user): ScopedContext
{
    app()->forgetScopedInstances();
    test()->actingAs($user);

    return app(ScopedContext::class);
}

/**
 * Tabla de prueba: una fila por cada combinación de campus (C1, C2, C3 o ninguno), unidad (D1, la oficina de
 * D1, D2, D3 o ninguna), carrera (X, Y o ninguna) y dueño (`$owner`, otro o ninguno): 180 filas.
 *
 * @param  array<string, string>  $f
 */
function authorizationProbe(array $f, string $owner): Model
{
    Schema::create('authorization_probes', function (Blueprint $table): void {
        $table->id();
        $table->uuid('campus_id')->nullable();
        $table->uuid('department_id')->nullable();
        $table->uuid('career_id')->nullable();
        $table->uuid('owner_id')->nullable();
    });

    $other = User::factory()->create()->id;
    $rows = [];

    foreach ([$f['c1'], $f['c2'], $f['c3'], null] as $campus) {
        foreach ([$f['d1'], $f['office'], $f['d2'], $f['d3'], null] as $department) {
            foreach ([$f['x'], $f['y'], null] as $career) {
                foreach ([$owner, $other, null] as $ownerId) {
                    $rows[] = ['campus_id' => $campus, 'department_id' => $department, 'career_id' => $career, 'owner_id' => $ownerId];
                }
            }
        }
    }

    DB::table('authorization_probes')->insert($rows);

    return new class extends Model
    {
        protected $table = 'authorization_probes';

        public $timestamps = false;
    };
}

/** @return array<string, string> */
function probeColumns(): array
{
    return ['campus' => 'campus_id', 'unit' => 'department_id', 'career' => 'career_id', 'owner' => 'owner_id'];
}

function probeTarget(FakePermission $permission, object $row): Target
{
    if ($permission->isPersonal()) {
        return $row->owner_id === null ? Target::of() : Target::ownedBy($row->owner_id);
    }

    return Target::of(campus: $row->campus_id, unit: $row->department_id, career: $row->career_id);
}

describe('allows()', function () {
    it('un puesto sin el permiso no puede aunque su alcance cubra', function () {
        $jefe = actorOf(authorizationUser('jefe', $this->f));

        expect($jefe->allows(FakePermission::ViewCareer, Target::of(career: $this->f['x'])))->toBeFalse()
            ->and($jefe->allows(FakePermission::ViewCampusCareer, Target::of(campus: $this->f['c2'], career: $this->f['x'])))->toBeFalse()
            ->and($jefe->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d1'])))->toBeTrue()
            ->and(actorOf(authorizationUser('docente', $this->f))->allows(FakePermission::ViewAny, Target::of()))->toBeFalse();
    });

    it('el jefe de un departamento puede sobre lo de su departamento y no sobre lo de otro', function () {
        $jefe = actorOf(authorizationUser('jefe', $this->f));

        expect($jefe->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d1'])))->toBeTrue()
            ->and($jefe->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['office'])))->toBeTrue()
            ->and($jefe->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->f['c2'], unit: $this->f['d1'])))->toBeTrue()
            ->and($jefe->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d2'])))->toBeFalse()
            ->and($jefe->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['academic'])))->toBeFalse()
            ->and($jefe->allows(FakePermission::ViewUnit, Target::of()))->toBeFalse()
            ->and($jefe->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->f['c3'], unit: $this->f['d1'])))->toBeFalse();
    });

    it('Subdirección puede sobre lo de cualquier departamento de su subárbol', function () {
        $subdireccion = actorOf(authorizationUser('subdirección', $this->f));

        expect($subdireccion->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d1'])))->toBeTrue()
            ->and($subdireccion->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['office'])))->toBeTrue()
            ->and($subdireccion->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d2'])))->toBeTrue()
            ->and($subdireccion->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['academic'])))->toBeTrue()
            ->and($subdireccion->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d3'])))->toBeFalse()
            ->and($subdireccion->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['planeacion'])))->toBeFalse();
    });

    it('un nombramiento sin campus vale en cualquier campus activo y no en uno desactivado', function () {
        $deleted = Campus::factory()->create(['code' => 'C4']);
        $deleted->delete();
        $subdireccion = actorOf(authorizationUser('subdirección', $this->f));

        expect($subdireccion->allows(FakePermission::ViewCampus, Target::of(campus: $this->f['c1'])))->toBeTrue()
            ->and($subdireccion->allows(FakePermission::ViewCampus, Target::of(campus: $this->f['c2'])))->toBeTrue()
            ->and($subdireccion->allows(FakePermission::ViewCampus, Target::of(campus: $this->f['c3'])))->toBeFalse()
            ->and($subdireccion->allows(FakePermission::ViewCampus, Target::of(campus: $deleted->id)))->toBeFalse();
    });

    it('un nombramiento en un campus desactivado no cuenta, ni en las dimensiones que el permiso no declara', function () {
        $user = authorizationUser('jefe de proyecto docente', $this->f);
        Campus::whereKey($this->f['c2'])->update(['is_active' => false]);
        $jefeDeProyecto = actorOf($user);

        expect($jefeDeProyecto->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->f['c2'], unit: $this->f['d2'])))->toBeFalse()
            ->and($jefeDeProyecto->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d2'])))->toBeFalse()
            ->and($jefeDeProyecto->allowsAny(FakePermission::ViewUnit))->toBeFalse();
    });

    it('un objetivo sin campus (general) solo lo cubre un nombramiento sin campus', function () {
        expect(actorOf(authorizationUser('subdirección', $this->f))->allows(FakePermission::ViewCampus, Target::of()))->toBeTrue()
            ->and(actorOf(authorizationUser('jefe', $this->f))->allows(FakePermission::ViewCampusUnit, Target::of(unit: $this->f['d1'])))->toBeTrue()
            ->and(actorOf(authorizationUser('coordinador', $this->f))->allows(FakePermission::ViewCampus, Target::of()))->toBeFalse()
            ->and(actorOf(authorizationUser('coordinador', $this->f))->allows(FakePermission::ViewCampusCareer, Target::of(career: $this->f['x'])))->toBeFalse();
    });

    it('las dimensiones que el permiso no declara no se revisan', function () {
        $jefeDeProyecto = actorOf(authorizationUser('jefe de proyecto docente', $this->f));

        expect($jefeDeProyecto->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d2'])))->toBeTrue()
            ->and($jefeDeProyecto->allows(FakePermission::ViewUnit, Target::of(campus: $this->f['c1'], unit: $this->f['d2'])))->toBeTrue()
            ->and($jefeDeProyecto->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->f['c1'], unit: $this->f['d2'])))->toBeFalse()
            ->and($jefeDeProyecto->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->f['c2'], unit: $this->f['d2'])))->toBeTrue()
            ->and($jefeDeProyecto->allows(FakePermission::ViewAny, Target::of()))->toBeTrue();
    });

    it('un objetivo con varios valores se cubre si alguno coincide', function () {
        $coordinador = actorOf(authorizationUser('coordinador', $this->f));

        expect($coordinador->allows(FakePermission::ViewCampusCareer, Target::of(campus: [$this->f['c1'], $this->f['c2']], career: [$this->f['y'], $this->f['x']])))->toBeTrue()
            ->and($coordinador->allows(FakePermission::ViewCampusCareer, Target::of(campus: [$this->f['c1']], career: [$this->f['x'], $this->f['y']])))->toBeFalse();

        $jefe = actorOf(authorizationUser('jefe', $this->f));

        expect($jefe->allows(FakePermission::ViewUnit, Target::of(unit: [$this->f['d2'], $this->f['office']])))->toBeTrue()
            ->and($jefe->allows(FakePermission::ViewUnit, Target::of(unit: [$this->f['d2'], $this->f['d3']])))->toBeFalse();

        $subdireccion = actorOf(authorizationUser('subdirección', $this->f));

        expect($subdireccion->allows(FakePermission::ViewCampus, Target::of(campus: [$this->f['c3'], $this->f['c1']])))->toBeTrue()
            ->and($subdireccion->allows(FakePermission::ViewCampus, Target::of(campus: [$this->f['c3']])))->toBeFalse();
    });

    it('con dos puestos basta que uno cubra, cada uno con su alcance', function () {
        // Sin perfil activo: el contexto de otro usuario (forUser) cuenta todos sus puestos.
        $user = app(ScopedContext::class)->forUser(authorizationUser('dos puestos', $this->f));

        expect($user->allows(FakePermission::ViewUnit, Target::of(unit: $this->f['d2'])))->toBeTrue()
            ->and($user->allows(FakePermission::ViewCampusCareer, Target::of(campus: $this->f['c1'], career: $this->f['y'])))->toBeTrue()
            ->and($user->allows(FakePermission::ViewCampusCareer, Target::of(campus: $this->f['c3'], career: $this->f['x'])))->toBeFalse()
            ->and($user->allows(FakePermission::ViewCareer, Target::of(career: $this->f['x'])))->toBeFalse();
    });

    it('el admin puede todo menos sus excepciones', function () {
        $admin = actorOf(authorizationUser('admin', $this->f));

        expect($admin->allows(FakePermission::ViewCampusUnitCareer, Target::of(campus: $this->f['c3'], unit: $this->f['d3'], career: $this->f['y'])))->toBeTrue()
            ->and($admin->allows(FakePermission::ViewUnit, Target::of()))->toBeTrue()
            ->and($admin->allows(FakePermission::ViewCampusCareer, Target::of(campus: $this->f['c1'], career: $this->f['x'])))->toBeFalse()
            ->and($admin->allowsAny(FakePermission::ViewCampusCareer))->toBeFalse()
            ->and($admin->allowsAny(FakePermission::ViewCampusUnit))->toBeTrue();
    });

    it('un permiso personal vale con cualquier puesto y solo sobre lo propio', function () {
        $docenteUser = authorizationUser('docente', $this->f);
        $docente = actorOf($docenteUser);

        expect($docente->allows(FakePermission::ViewOwn, Target::ownedBy($docenteUser->id)))->toBeTrue()
            ->and($docente->allows(FakePermission::ViewOwn, Target::ownedBy(User::factory()->create()->id)))->toBeFalse()
            ->and($docente->allows(FakePermission::ViewOwn, Target::of()))->toBeFalse()
            ->and($docente->allows(FakePermission::ViewOwn))->toBeFalse()
            ->and($docente->allowsAny(FakePermission::ViewOwn))->toBeTrue();

        $jefeUser = authorizationUser('jefe', $this->f);
        $adminUser = authorizationUser('admin', $this->f);

        expect(actorOf($jefeUser)->allows(FakePermission::ViewOwn, Target::ownedBy($jefeUser->id)))->toBeFalse()
            ->and(actorOf($jefeUser)->allowsAny(FakePermission::ViewOwn))->toBeFalse()
            ->and(actorOf($adminUser)->allows(FakePermission::ViewOwn, Target::ownedBy($adminUser->id)))->toBeTrue()
            ->and(actorOf($adminUser)->allows(FakePermission::ViewOwn, Target::ownedBy($docenteUser->id)))->toBeFalse();
    });

    it('sin usuario activo no puede nada', function () {
        $inactive = User::factory()->admin()->create(['is_active' => false]);

        expect(actorOf($inactive)->allows(FakePermission::ViewAny, Target::of()))->toBeFalse()
            ->and(actorOf($inactive)->allowsAny(FakePermission::ViewAny))->toBeFalse()
            ->and(actorOf($inactive)->allows(FakePermission::ViewOwn, Target::ownedBy($inactive->id)))->toBeFalse();
    });
});

describe('allowsAny()', function () {
    it('allowsAny responde si el puesto tiene el permiso y algún nombramiento vigente', function () {
        $coordinador = actorOf(authorizationUser('coordinador', $this->f));
        $expired = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->f['c2'], 'career_id' => $this->f['x']])->create();
        RoleAssignment::where('user_id', $expired->id)->update(['valid_to' => today()->subDay()->toDateString()]);
        $inactiveCampus = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->f['c3'], 'career_id' => $this->f['x']])->create();

        expect($coordinador->allowsAny(FakePermission::ViewCareer))->toBeTrue()
            ->and($coordinador->allows(FakePermission::ViewCareer))->toBeTrue()
            ->and(actorOf(authorizationUser('jefe', $this->f))->allowsAny(FakePermission::ViewCareer))->toBeFalse()
            ->and(actorOf($expired)->allowsAny(FakePermission::ViewCareer))->toBeFalse()
            ->and(actorOf($inactiveCampus)->allowsAny(FakePermission::ViewCareer))->toBeFalse()
            ->and(actorOf(User::factory()->create())->allowsAny(FakePermission::ViewAny))->toBeFalse()
            ->and(actorOf(authorizationUser('admin', $this->f))->allowsAny(FakePermission::ViewAny))->toBeTrue();
    });
});

describe('constrain()', function () {
    it('constrain filtra en SQL igual que allows', function (string $kind, FakePermission $permission) {
        $user = authorizationUser($kind, $this->f);
        $probe = authorizationProbe($this->f, $user->id);
        $actor = actorOf($user);

        $allowed = DB::table('authorization_probes')->orderBy('id')->get()
            ->filter(fn (object $row): bool => $actor->allows($permission, probeTarget($permission, $row)))
            ->pluck('id')->values()->all();
        $constrained = $actor->constrain($probe->newQuery(), $permission, probeColumns())->orderBy('id')->pluck('id')->all();

        expect($constrained)->toBe($allowed);
    })->with(['jefe', 'jefe de proyecto docente', 'coordinador', 'subdirección', 'admin', 'docente', 'dos puestos'])
        ->with(FakePermission::cases());

    it('deja fuera lo de un campus desactivado y deja dentro lo general a un nombramiento sin campus', function () {
        $user = authorizationUser('subdirección', $this->f);
        $probe = authorizationProbe($this->f, $user->id);

        $campuses = actorOf($user)->constrain($probe->newQuery(), FakePermission::ViewCampus, probeColumns())
            ->distinct()->pluck('campus_id')->all();

        expect($campuses)->toEqualCanonicalizing([$this->f['c1'], $this->f['c2'], null]);
    });

    it('sin el permiso no deja nada, y al admin no lo filtra', function () {
        $jefe = authorizationUser('jefe', $this->f);
        $probe = authorizationProbe($this->f, $jefe->id);

        expect(actorOf($jefe)->constrain($probe->newQuery(), FakePermission::ViewCareer, probeColumns())->count())->toBe(0)
            ->and(actorOf(authorizationUser('admin', $this->f))->constrain($probe->newQuery(), FakePermission::ViewUnit, probeColumns())->count())->toBe(180)
            ->and(actorOf(authorizationUser('admin', $this->f))->constrain($probe->newQuery(), FakePermission::ViewCampusCareer, probeColumns())->count())->toBe(0);
    });

    it('acepta una Closure para la unidad o la carrera con los valores que cubre', function () {
        $user = authorizationUser('dos puestos', $this->f);
        $probe = authorizationProbe($this->f, $user->id);
        $actor = actorOf($user);
        $byColumn = $actor->constrain($probe->newQuery(), FakePermission::ViewCampusUnitCareer, probeColumns())->orderBy('id')->pluck('id')->all();
        $received = [];
        $columns = [
            ...probeColumns(),
            'unit' => function (Builder $query, array $units) use (&$received): void {
                $received[] = $units;
                $query->whereIn('department_id', $units);
            },
            'career' => fn (Builder $query, array $careers) => $query->whereIn('career_id', $careers),
        ];

        $byClosure = $actor->constrain($probe->newQuery(), FakePermission::ViewCampusUnitCareer, $columns)->orderBy('id')->pluck('id')->all();

        expect($byClosure)->toBe($byColumn)->not->toBeEmpty()
            ->and($received)->toBe([[$this->f['d2']]]);
    });

    it('constrain rechaza una consulta con un OR suelto, y con el OR agrupado la sigue filtrando', function (string $kind, int $expected) {
        $user = authorizationUser($kind, $this->f);
        $probe = authorizationProbe($this->f, $user->id);
        $actor = actorOf($user);
        $loose = $probe->newQuery()->where('campus_id', $this->f['c1'])->orWhere('campus_id', $this->f['c3']);
        $grouped = $probe->newQuery()->where(fn (Builder $query) => $query->where('campus_id', $this->f['c1'])->orWhere('campus_id', $this->f['c3']));

        expect(fn () => $actor->constrain($loose, FakePermission::ViewCampus, probeColumns()))
            ->toThrow(LogicException::class, 'Agrupa tus condiciones con OR en un where(function …) antes de llamar a constrain()')
            ->and($actor->constrain($grouped, FakePermission::ViewCampus, probeColumns())->count())->toBe($expected);
    })->with([
        'un nombramiento sin campus: solo C1, no C3 (desactivado)' => ['jefe', 45],
        'sin el permiso: nada' => ['docente', 0],
        'el admin: C1 y C3' => ['admin', 90],
    ]);

    it('falla si falta la columna de una dimensión que el permiso declara, o si el campus viene como Closure', function (FakePermission $permission, array $columns) {
        $user = authorizationUser('admin', $this->f);

        expect(fn () => actorOf($user)->constrain(User::query(), $permission, $columns))->toThrow(LogicException::class);
    })->with([
        'sin unidad' => [FakePermission::ViewCampusUnit, ['campus' => 'campus_id']],
        'sin campus' => [FakePermission::ViewCampus, ['unit' => 'department_id']],
        'sin dueño' => [FakePermission::ViewOwn, ['campus' => 'campus_id']],
        'campus con Closure' => [FakePermission::ViewCampus, ['campus' => fn (Builder $query, array $values) => $query->whereIn('campus_id', $values)]],
    ]);
});
