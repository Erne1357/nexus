<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| Departamentos 10 y 11, carreras 20 y 21: enteros porque Core no depende de Academic.
| "Hoy" es el 27 de octubre de 2026 en Ciudad Juárez.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00', 'America/Ciudad_Juarez'));
    $this->seed(RoleSeeder::class);

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

function scopedContextOf(User $user): ScopedContext
{
    test()->actingAs($user);

    return app(ScopedContext::class);
}

describe('alcance por rol', function () {
    it('el admin tiene todos los roles y cubre cualquier campus, departamento y carrera sin alcance', function () {
        $context = scopedContextOf(User::factory()->admin()->create());

        expect($context->isAdmin())->toBeTrue()
            ->and(collect(Role::cases())->every(fn (Role $role): bool => $context->has($role)))->toBeTrue()
            ->and($context->covers(Role::JefeDepartamento, $this->c1, department: fakeKey(10)))->toBeTrue()
            ->and($context->covers(Role::Coordinador, $this->c2->id, career: fakeKey(21)))->toBeTrue()
            ->and($context->canAccessCampus($this->c1))->toBeTrue()
            ->and($context->scopes())->toBeEmpty();
    });

    it('el admin trabaja en todos los campus activos, pero no en los inactivos', function () {
        Campus::factory()->create(['code' => 'EAD', 'is_active' => false]);

        $context = scopedContextOf(User::factory()->admin()->create());

        expect($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2']);
    });

    it('un coordinador de la carrera X en C2 cubre X en C2, pero no la carrera Y ni C1', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])->create());

        expect($context->has(Role::Coordinador))->toBeTrue()
            ->and($context->covers(Role::Coordinador, $this->c2, career: fakeKey(20)))->toBeTrue()
            ->and($context->covers(Role::Coordinador, $this->c2, department: fakeKey(10), career: fakeKey(20)))->toBeTrue()
            ->and($context->covers(Role::Coordinador, $this->c2, career: fakeKey(21)))->toBeFalse()
            ->and($context->covers(Role::Coordinador, $this->c1, career: fakeKey(20)))->toBeFalse()
            ->and($context->covers(Role::Coordinador, $this->c2))->toBeFalse()
            ->and($context->canAccessCampus($this->c2))->toBeTrue()
            ->and($context->canAccessCampus($this->c1))->toBeFalse()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C2']);
    });

    it('un jefe del departamento D en C2 cubre D en C2, pero no otro departamento ni C1', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => fakeKey(10)])->create());

        expect($context->has(Role::JefeDepartamento))->toBeTrue()
            ->and($context->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10)))->toBeTrue()
            ->and($context->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10), career: fakeKey(21)))->toBeTrue()
            ->and($context->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(11)))->toBeFalse()
            ->and($context->covers(Role::JefeDepartamento, $this->c1, department: fakeKey(10)))->toBeFalse()
            ->and($context->covers(Role::JefeDepartamento, $this->c2))->toBeFalse()
            ->and($context->covers(Role::JefeProyectoDocente, $this->c2, department: fakeKey(10)))->toBeFalse();
    });

    it('un jefe de proyecto docente del departamento D en C2 cubre D en C2 como su propio rol, no como jefe', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => fakeKey(10)])->create());

        expect($context->covers(Role::JefeProyectoDocente, $this->c2, department: fakeKey(10)))->toBeTrue()
            ->and($context->covers(Role::JefeProyectoDocente, $this->c2, department: fakeKey(11)))->toBeFalse()
            ->and($context->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10)))->toBeFalse();
    });

    it('la subdirección de C2 cubre todo C2, pero no C1', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create());

        expect($context->covers(Role::Subdireccion, $this->c2))->toBeTrue()
            ->and($context->covers(Role::Subdireccion, $this->c2, department: fakeKey(10), career: fakeKey(20)))->toBeTrue()
            ->and($context->covers(Role::Subdireccion, $this->c1))->toBeFalse()
            ->and($context->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10)))->toBeFalse();
    });

    it('un docente trabaja en su campus pero no cubre ningún rol de gestión', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());
        $management = [Role::Subdireccion, Role::JefeDepartamento, Role::JefeProyectoDocente, Role::Coordinador];

        expect($context->has(Role::Docente))->toBeTrue()
            ->and($context->canAccessCampus($this->c2))->toBeTrue()
            ->and($context->isAdmin())->toBeFalse()
            ->and(collect($management)->contains(fn (Role $role): bool => $context->has($role)))->toBeFalse()
            ->and($context->covers($management, $this->c2, department: fakeKey(10), career: fakeKey(20)))->toBeFalse();
    });

    it('un docente nunca cubre por covers(), ni con Role::Docente: su carga se autoriza por asignación', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());

        expect($context->covers(Role::Docente, $this->c2))->toBeFalse()
            ->and($context->covers([Role::Docente, Role::Coordinador], $this->c2, department: fakeKey(10), career: fakeKey(20)))->toBeFalse()
            ->and($context->has(Role::Docente))->toBeTrue()
            ->and($context->canAccessCampus($this->c2))->toBeTrue();
    });

    it('cubre con cualquiera de varios roles el que el usuario sí tiene', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])->create());

        expect($context->covers([Role::JefeDepartamento, Role::Coordinador], $this->c2, department: fakeKey(10), career: fakeKey(20)))->toBeTrue();
    });
});

describe('vigencia y coherencia del alcance', function () {
    it('un alcance vencido o futuro no cuenta', function (string $from, ?string $to) {
        $user = User::factory()->create()->assignRole(Role::JefeDepartamento);
        RoleScope::factory()->for($user)->create([
            'role' => Role::JefeDepartamento, 'campus_id' => $this->c2->id, 'department_id' => fakeKey(10),
            'valid_from' => $from, 'valid_to' => $to,
        ]);

        $context = scopedContextOf($user);

        expect($context->has(Role::JefeDepartamento))->toBeFalse()
            ->and($context->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10)))->toBeFalse()
            ->and($context->canAccessCampus($this->c2))->toBeFalse()
            ->and($context->scopes())->toBeEmpty();
    })->with([
        'venció ayer' => ['2026-01-01', '2026-10-26'],
        'empieza mañana' => ['2026-10-28', null],
    ]);

    it('cuenta el alcance que empieza o termina hoy', function (string $from, ?string $to) {
        $user = User::factory()->create()->assignRole(Role::JefeDepartamento);
        RoleScope::factory()->for($user)->create([
            'role' => Role::JefeDepartamento, 'campus_id' => $this->c2->id, 'department_id' => fakeKey(10),
            'valid_from' => $from, 'valid_to' => $to,
        ]);

        expect(scopedContextOf($user)->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10)))->toBeTrue();
    })->with([
        'empieza hoy' => ['2026-10-27', null],
        'termina hoy' => ['2026-01-01', '2026-10-27'],
    ]);

    it('decide la vigencia con la fecha de Ciudad Juárez, no con la de UTC', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 03:00', 'UTC'));
        $endsToday = User::factory()->create()->assignRole(Role::Docente);
        $startsTomorrow = User::factory()->create()->assignRole(Role::Docente);
        RoleScope::factory()->for($endsToday)->create(['role' => Role::Docente, 'campus_id' => $this->c2->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-10-27']);
        RoleScope::factory()->for($startsTomorrow)->create(['role' => Role::Docente, 'campus_id' => $this->c2->id, 'valid_from' => '2026-10-28']);

        expect(scopedContextOf($endsToday)->has(Role::Docente))->toBeTrue()
            ->and(scopedContextOf($startsTomorrow)->has(Role::Docente))->toBeFalse();
    });

    it('un alcance en un campus desactivado o borrado no cuenta', function (string $retired) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])->create();
        $retired === 'borrado' ? $this->c2->delete() : $this->c2->update(['is_active' => false]);

        $context = scopedContextOf($user);

        expect($context->has(Role::Coordinador))->toBeFalse()
            ->and($context->covers(Role::Coordinador, $this->c2->id, career: fakeKey(20)))->toBeFalse()
            ->and($context->canAccessCampus($this->c2->id))->toBeFalse()
            ->and($context->scopes())->toBeEmpty();
    })->with(['desactivado', 'borrado']);

    it('el admin sigue cubriendo los campus desactivados y borrados', function () {
        $this->c1->update(['is_active' => false]);
        $this->c2->delete();

        $context = scopedContextOf(User::factory()->admin()->create());

        expect($context->covers(Role::Coordinador, $this->c1->id, career: fakeKey(20)))->toBeTrue()
            ->and($context->canAccessCampus($this->c2->id))->toBeTrue();
    });

    it('refresh() vuelve a cargar roles y alcances en la misma petición', function () {
        $user = User::factory()->create();
        $context = scopedContextOf($user);

        expect($context->covers(Role::Coordinador, $this->c2, career: fakeKey(20)))->toBeFalse();

        User::find($user->id)->assignRole(Role::Coordinador);
        RoleScope::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => fakeKey(20)]);

        expect($context->covers(Role::Coordinador, $this->c2, career: fakeKey(20)))->toBeFalse();

        $context->refresh();

        expect($context->covers(Role::Coordinador, $this->c2, career: fakeKey(20)))->toBeTrue();
    });

    it('un rol de spatie sin alcance vigente no da acceso', function () {
        $context = scopedContextOf(User::factory()->create()->assignRole(Role::JefeDepartamento));

        expect($context->has(Role::JefeDepartamento))->toBeFalse()
            ->and($context->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10)))->toBeFalse()
            ->and($context->canAccessCampus($this->c2))->toBeFalse()
            ->and($context->campuses())->toBeEmpty();
    });

    it('un alcance sin el rol de spatie no da acceso', function () {
        $user = User::factory()->create();
        RoleScope::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => fakeKey(20)]);

        $context = scopedContextOf($user);

        expect($context->has(Role::Coordinador))->toBeFalse()
            ->and($context->covers(Role::Coordinador, $this->c2, career: fakeKey(20)))->toBeFalse()
            ->and($context->canAccessCampus($this->c2))->toBeFalse();
    });

    it('un alcance al que le falta lo que exige su rol no cuenta', function (Role $role, array $scope) {
        $user = User::factory()->create()->assignRole($role);
        RoleScope::factory()->for($user)->create(['role' => $role, ...$scope]);

        $context = scopedContextOf($user);

        expect($context->has($role))->toBeFalse()
            ->and($context->covers($role, $this->c2, department: fakeKey(10), career: fakeKey(20)))->toBeFalse()
            ->and($context->canAccessCampus($this->c2))->toBeFalse();
    })->with([
        'coordinador sin carrera' => fn () => [Role::Coordinador, ['campus_id' => $this->c2->id]],
        'jefe sin departamento' => fn () => [Role::JefeDepartamento, ['campus_id' => $this->c2->id]],
        'subdirección sin campus' => fn () => [Role::Subdireccion, ['campus_id' => null]],
        'docente sin campus' => fn () => [Role::Docente, ['campus_id' => null]],
    ]);

    it('un usuario inactivo no tiene ningún rol, ni siquiera admin', function () {
        $admin = User::factory()->admin()->create(['is_active' => false]);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => fakeKey(10)])->create(['is_active' => false]);

        expect(scopedContextOf($admin)->isAdmin())->toBeFalse()
            ->and(scopedContextOf($admin)->canAccessCampus($this->c2))->toBeFalse()
            ->and(scopedContextOf($jefe)->covers(Role::JefeDepartamento, $this->c2, department: fakeKey(10)))->toBeFalse();
    });

    it('sin usuario autenticado no hay roles, campus ni periodo', function () {
        Term::factory()->current()->create();

        $context = app(ScopedContext::class);

        expect($context->user())->toBeNull()
            ->and($context->isAdmin())->toBeFalse()
            ->and($context->canAccessCampus($this->c2))->toBeFalse()
            ->and($context->campuses())->toBeEmpty()
            ->and($context->activeCampus())->toBeNull()
            ->and($context->activeTerm())->toBeNull();
    });
});

describe('campus y periodo activos por omisión', function () {
    it('el periodo activo por omisión es el actual (is_current)', function () {
        Term::factory()->create();
        $current = Term::factory()->current()->create();

        $context = scopedContextOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());

        expect($context->activeTerm()?->is($current))->toBeTrue();
    });

    it('recuerda el periodo activo en la petición, pero sigue una elección nueva', function () {
        $current = Term::factory()->current()->create();
        $previous = Term::factory()->create();
        $context = scopedContextOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());
        $context->activeTerm();

        DB::enableQueryLog();

        expect($context->activeTerm()?->is($current))->toBeTrue()
            ->and(DB::getQueryLog())->toBeEmpty();

        app(SetActiveContext::class)->handle(null, $previous);

        expect($context->activeTerm()?->is($previous))->toBeTrue();
    });

    // ADR-0014: una sesión de antes del cambio a UUID guarda el id entero del periodo.
    it('con un periodo guardado que no es uuid usa el actual', function () {
        $current = Term::factory()->current()->create();
        $context = scopedContextOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());
        session([ScopedContext::ACTIVE_TERM => 3]);

        expect($context->activeTerm()?->is($current))->toBeTrue();
    });

    it('el campus activo por omisión es el único del alcance', function () {
        $context = scopedContextOf(User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => fakeKey(10)])->create());

        expect($context->activeCampus()?->is($this->c2))->toBeTrue();
    });

    it('no elige campus activo por omisión si el alcance tiene varios', function () {
        $user = User::factory()
            ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c1->id, 'department_id' => fakeKey(10)])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => fakeKey(20)])
            ->create();

        $context = scopedContextOf($user);

        expect($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2'])
            ->and($context->activeCampus())->toBeNull();
    });
});

describe('ciclo de vida en el contenedor (Octane)', function () {
    it('se registra como scoped: una instancia por petición, no un singleton', function () {
        $first = app(ScopedContext::class);

        expect(app(ScopedContext::class))->toBe($first);

        app()->forgetScopedInstances();

        expect(app(ScopedContext::class))->not->toBe($first);
    });

    it('no filtra el alcance ni el campus activo entre dos usuarios seguidos en la misma app', function () {
        Route::middleware('web')->get('/_test/scoped-context', fn (ScopedContext $context): array => [
            'campuses' => $context->campuses()->pluck('code')->all(),
            'active' => $context->activeCampus()?->code,
        ]);
        $both = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->c1->id])->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])->create();
        $onlyC2 = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create();

        $this->actingAs($both);
        app(SetActiveContext::class)->handle($this->c1, null);
        $this->get('/_test/scoped-context')->assertExactJson(['campuses' => ['C1', 'C2'], 'active' => 'C1']);

        $this->actingAs($onlyC2)->get('/_test/scoped-context')->assertExactJson(['campuses' => ['C2'], 'active' => 'C2']);
    });
});
