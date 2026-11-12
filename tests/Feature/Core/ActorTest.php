<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Authorization\FakePermission;

/*
| Departamentos 10 y 11, carreras 20 y 21: enteros porque Core no depende de Academic. Lo que cada puesto puede se
| pregunta con los permisos de prueba (useFakePermissions); la regla de decisión misma está en AuthorizationTest.
| "Hoy" es el 27 de octubre de 2026 en Ciudad Juárez.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00', 'America/Ciudad_Juarez'));
    useFakePermissions();

    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
});

function actorOf(User $user): Actor
{
    test()->actingAs($user);

    return app(Actor::class);
}

describe('alcance por rol', function () {
    it('el admin trabaja en todos los campus activos, pero no en los inactivos', function () {
        Campus::factory()->create(['code' => 'EAD', 'is_active' => false]);

        $context = actorOf(User::factory()->admin()->create());

        expect($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2']);
    });

    it('un docente trabaja en su campus pero no cubre ningún rol de gestión', function () {
        $context = actorOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());

        expect($context->heldRoles())->toBe([Role::Docente])
            ->and($context->campuses()->pluck('code')->all())->toBe(['C2'])
            ->and($context->allowsAny(FakePermission::ViewAny))->toBeFalse();
    });

    it('da los roles que el usuario ejerce, en el orden del enum; al admin, solo el suyo', function () {
        $jefeYDocente = User::factory()
            ->withRole(Role::Docente, ['campus_id' => $this->c2->id])
            ->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
            ->create();
        $vencido = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        RoleAssignment::where('user_id', $vencido->id)->update(['valid_to' => today()->subDay()]);

        expect(actorOf($jefeYDocente)->heldRoles())->toBe([Role::JefeDepartamento, Role::Docente])
            ->and(actorOf(User::factory()->admin()->create())->heldRoles())->toBe([Role::Admin])
            ->and(actorOf($vencido)->heldRoles())->toBe([])
            ->and(actorOf(User::factory()->admin()->create(['is_active' => false]))->heldRoles())->toBe([]);
    });
});

describe('vigencia y coherencia del alcance', function () {
    it('un alcance vencido o futuro no cuenta', function (string $from, ?string $to) {
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->create([
            'role' => Role::JefeDepartamento, 'campus_id' => $this->c2->id, 'org_unit_id' => departmentKey(10),
            'valid_from' => $from, 'valid_to' => $to,
        ]);

        $context = actorOf($user);

        expect($context->heldRoles())->toBe([])
            ->and($context->heldScopes())->toBeEmpty()
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse()
            ->and($context->campuses())->toBeEmpty();
    })->with([
        'venció ayer' => ['2026-01-01', '2026-10-26'],
        'empieza mañana' => ['2026-10-28', null],
    ]);

    it('cuenta el alcance que empieza o termina hoy', function (string $from, ?string $to) {
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->create([
            'role' => Role::JefeDepartamento, 'campus_id' => $this->c2->id, 'org_unit_id' => departmentKey(10),
            'valid_from' => $from, 'valid_to' => $to,
        ]);

        $context = actorOf($user);

        expect($context->heldRoles())->toBe([Role::JefeDepartamento])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeTrue()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C2']);
    })->with([
        'empieza hoy' => ['2026-10-27', null],
        'termina hoy' => ['2026-01-01', '2026-10-27'],
    ]);

    it('decide la vigencia con la fecha de Ciudad Juárez, no con la de UTC', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-28 03:00', 'UTC'));
        $endsToday = User::factory()->create();
        $startsTomorrow = User::factory()->create();
        RoleAssignment::factory()->for($endsToday)->create(['role' => Role::Docente, 'campus_id' => $this->c2->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-10-27']);
        RoleAssignment::factory()->for($startsTomorrow)->create(['role' => Role::Docente, 'campus_id' => $this->c2->id, 'valid_from' => '2026-10-28']);

        expect(actorOf($endsToday)->heldRoles())->toBe([Role::Docente])
            ->and(actorOf($startsTomorrow)->heldRoles())->toBe([]);
    });

    it('un alcance en un campus desactivado o borrado no cuenta', function (string $retired) {
        $user = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();
        $retired === 'borrado' ? $this->c2->delete() : $this->c2->update(['is_active' => false]);

        $context = actorOf($user);

        expect($context->heldRoles())->toBe([])
            ->and($context->heldScopes())->toBeEmpty()
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse()
            ->and($context->campuses())->toBeEmpty();
    })->with(['desactivado', 'borrado']);

    it('el admin sigue cubriendo los campus desactivados y borrados', function () {
        $this->c1->update(['is_active' => false]);
        $this->c2->delete();

        $context = actorOf(User::factory()->admin()->create());

        expect($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c1->id)))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeTrue();
    });

    it('refresh() vuelve a cargar roles y alcances en la misma petición', function () {
        $user = User::factory()->create();
        $context = actorOf($user);

        expect($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse();

        RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => careerKey(20)]);

        expect($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse();

        $context->refresh();

        expect($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeTrue()
            ->and($context->heldRoles())->toBe([Role::Coordinador]);
    });

    it('un usuario inactivo no tiene ningún rol, ni siquiera admin', function () {
        $admin = User::factory()->admin()->create(['is_active' => false]);
        $jefe = User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create(['is_active' => false]);

        expect(actorOf($admin)->heldRoles())->toBe([])
            ->and(actorOf($admin)->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse()
            ->and(actorOf($admin)->campuses())->toBeEmpty()
            ->and(actorOf($jefe)->heldRoles())->toBe([])
            ->and(actorOf($jefe)->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse();
    });

    it('el admin es un nombramiento: sin él, el usuario no es admin aunque alguna vez lo fue', function () {
        $admin = User::factory()->admin()->create();
        $admin->roleAssignments()->where('role', Role::Admin)->update(['valid_to' => today()->subDay()->toDateString()]);

        $context = actorOf($admin->fresh());

        expect($context->heldRoles())->toBe([])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c1->id)))->toBeFalse();
    });

    it('sin usuario autenticado no hay roles, campus ni periodo', function () {
        Term::factory()->current()->create();

        $context = app(Actor::class);

        expect($context->user())->toBeNull()
            ->and($context->heldRoles())->toBe([])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse()
            ->and($context->campuses())->toBeEmpty()
            ->and($context->activeCampus())->toBeNull()
            ->and($context->activeTerm())->toBeNull();
    });
});

describe('alcance de todo el Tec (sin campus)', function () {
    it('la subdirección sin campus cubre todos los campus activos y los ofrece en la credencial', function () {
        $context = actorOf(User::factory()->withRole(Role::Subdireccion)->create());

        expect($context->heldRoles())->toBe([Role::Subdireccion])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c1->id)))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->c2->id, unit: departmentKey(10))))->toBeTrue()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2'])
            ->and($context->activeCampus())->toBeNull();
    });

    it('un alcance sin campus no cubre un campus desactivado o borrado: eso queda para el admin', function (string $retired) {
        $c3 = Campus::factory()->create(['code' => 'C3']);
        $retired === 'borrado' ? $c3->delete() : $c3->update(['is_active' => false]);

        $context = actorOf(User::factory()->withRole(Role::Subdireccion)->create());

        expect($context->allows(FakePermission::ViewCampus, Target::of(campus: $c3->id)))->toBeFalse()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2'])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeTrue();
    })->with(['desactivado', 'borrado']);

    it('un jefe del departamento D sin campus cubre D en C1 y en C2, pero no otro departamento', function () {
        $context = actorOf(User::factory()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create());

        expect($context->heldRoles())->toBe([Role::JefeDepartamento])
            ->and($context->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->c1->id, unit: departmentKey(10))))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, Target::of(campus: $this->c2->id, unit: departmentKey(10), career: careerKey(21))))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->c2->id, unit: departmentKey(11))))->toBeFalse()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2']);
    });

    it('un docente sin campus trabaja en cualquier campus activo, pero no cubre nada de gestión', function () {
        Campus::factory()->create(['code' => 'EAD', 'is_active' => false]);
        $context = actorOf(User::factory()->withRole(Role::Docente)->create());

        expect($context->heldRoles())->toBe([Role::Docente])
            ->and($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2'])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse()
            ->and($context->allowsAny(FakePermission::ViewAny))->toBeFalse();
    });

    it('un alcance de docente sin campus no extiende a otro campus el de su coordinación', function () {
        // Con los dos puestos a la vez: el contexto de otro usuario (forUser), sin perfil activo.
        $context = app(Actor::class)->forUser(User::factory()
            ->withRole(Role::Docente)
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create());

        expect($context->allows(FakePermission::ViewCampusCareer, Target::of(campus: $this->c2->id, career: careerKey(20))))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampusCareer, Target::of(campus: $this->c1->id, career: careerKey(20))))->toBeFalse()
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c1->id)))->toBeFalse()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2']);
    });

    it('los campus de la credencial salen del puesto activo', function () {
        Campus::factory()->create(['code' => 'EAD', 'is_active' => false]);
        $user = User::factory()
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->withRole(Role::Docente)
            ->create();

        actingAsRole($user, Role::Coordinador);
        $context = app(Actor::class);

        expect($context->campuses()->pluck('code')->all())->toBe(['C2'])
            ->and($context->activeCampus()?->is($this->c2))->toBeTrue();

        actingAsRole($user, Role::Docente);

        expect($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2'])
            ->and($context->activeCampus())->toBeNull();
    });

    it('un coordinador de un campus desactivado o borrado que también es docente de todo el Tec solo actúa como docente: la credencial ofrece los campus activos', function (string $retired) {
        $user = User::factory()
            ->withRole(Role::Docente)
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create(['last_role' => Role::Coordinador]);
        $retired === 'borrado' ? $this->c2->delete() : $this->c2->update(['is_active' => false]);

        $context = actorOf($user);

        expect($context->activeRole())->toBe(Role::Docente)
            ->and($context->campuses()->pluck('code')->all())->toBe(['C1'])
            ->and($context->activeCampus()?->is($this->c1))->toBeTrue()
            ->and($context->heldRoles())->toBe([Role::Docente]);
    })->with(['desactivado', 'borrado']);

    it('la credencial de un docente de todo el Tec con una coordinación en un campus desactivado y otra activa ofrece solo la activa', function () {
        $c3 = Campus::factory()->create(['code' => 'C3']);
        $user = User::factory()
            ->withRole(Role::Docente)
            ->withRole(Role::Coordinador, ['campus_id' => $c3->id, 'career_id' => careerKey(20)])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create();
        $c3->update(['is_active' => false]);

        expect(actorOf($user)->campuses()->pluck('code')->all())->toBe(['C2']);
    });

    it('con otro rol de todo el Tec, la credencial de un docente de todo el Tec ofrece todos los campus activos', function () {
        $context = actorOf(User::factory()
            ->withRole(Role::Docente)
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
            ->create());

        expect($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2'])
            ->and($context->activeCampus())->toBeNull();
    });

    it('un alcance sin campus de un usuario inactivo no cuenta', function () {
        $context = actorOf(User::factory()->withRole(Role::Subdireccion)->create(['is_active' => false]));

        expect($context->heldRoles())->toBe([])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse()
            ->and($context->allows(FakePermission::ViewUnit, Target::of(unit: departmentKey(10))))->toBeFalse()
            ->and($context->campuses())->toBeEmpty();
    });

    it('no toma los alcances de otro usuario: los alcances sin campus o de campus activo son solo los suyos', function () {
        $withoutScopes = User::factory()->create();
        User::factory()
            ->withRole(Role::Subdireccion, ['campus_id' => $this->c2->id])
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
            ->create();

        $context = actorOf($withoutScopes);

        expect($context->heldRoles())->toBe([])
            ->and($context->heldScopes())->toBeEmpty()
            ->and($context->allows(FakePermission::ViewCampusUnit, Target::of(campus: $this->c2->id, unit: departmentKey(10))))->toBeFalse()
            ->and($context->allows(FakePermission::ViewUnit, Target::of(unit: departmentKey(10))))->toBeFalse()
            ->and($context->allowsAny(FakePermission::ViewAny))->toBeFalse()
            ->and($context->campuses())->toBeEmpty();
    });

    it('un alcance sin campus vencido no cuenta', function () {
        $context = actorOf(User::factory()->withRole(Role::Subdireccion, ['valid_from' => '2026-01-01', 'valid_to' => '2026-10-26'])->create());

        expect($context->heldRoles())->toBe([])
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeFalse()
            ->and($context->campuses())->toBeEmpty();
    });

    it('averigua una sola vez por petición qué campus están activos', function () {
        $context = actorOf(User::factory()->withRole(Role::Subdireccion)->create());
        $context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c1->id));

        DB::enableQueryLog();

        expect($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c2->id)))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampus, Target::of(campus: $this->c1->id)))->toBeTrue()
            ->and($context->campuses())->toHaveCount(2)
            ->and(DB::getQueryLog())->toBeEmpty();
    });
});

describe('unidades del organigrama', function () {
    it('Subdirección cubre los departamentos del subárbol de Subdirección Académica y deja de cubrir uno que se mueve fuera', function () {
        $inside = departmentKey(10);
        $moved = departmentKey(11);
        $division = OrgUnit::factory()->create(['kind' => OrgUnitKind::Division, 'parent_id' => OrgUnit::where('code', OrgTree::ACADEMIC)->value('id')]);
        $planeacion = OrgUnit::factory()->create(['kind' => OrgUnitKind::Subdireccion]);
        $user = User::factory()->withRole(Role::Subdireccion)->create();
        $nextRequest = function () use ($user): Actor {
            app()->forgetScopedInstances();

            return actorOf($user);
        };

        $context = actorOf($user);

        expect($context->allows(FakePermission::ViewUnit, Target::of(unit: $inside)))->toBeTrue()
            ->and($context->allows(FakePermission::ViewUnit, Target::of(unit: $moved)))->toBeTrue();

        OrgUnit::find($moved)->update(['parent_id' => $division->id]);
        $context = $nextRequest();

        expect($context->allows(FakePermission::ViewUnit, Target::of(unit: $moved)))->toBeTrue();

        OrgUnit::find($moved)->update(['parent_id' => $planeacion->id]);
        $context = $nextRequest();

        expect($context->allows(FakePermission::ViewUnit, Target::of(unit: $inside)))->toBeTrue()
            ->and($context->allows(FakePermission::ViewUnit, Target::of(unit: $moved)))->toBeFalse();
    });
});

describe('campus y periodo activos por omisión', function () {
    it('el periodo activo por omisión es el actual (is_current)', function () {
        Term::factory()->create();
        $current = Term::factory()->current()->create();

        $context = actorOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());

        expect($context->activeTerm()?->is($current))->toBeTrue();
    });

    it('recuerda el periodo activo en la petición, pero sigue una elección nueva', function () {
        $current = Term::factory()->current()->create();
        $previous = Term::factory()->create();
        $context = actorOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());
        $context->activeTerm();

        DB::enableQueryLog();

        expect($context->activeTerm()?->is($current))->toBeTrue()
            ->and(DB::getQueryLog())->toBeEmpty();

        app(SetActiveContext::class)->handle($previous);

        expect($context->activeTerm()?->is($previous))->toBeTrue();
    });

    // ADR-0014: una sesión de antes del cambio a UUID guarda el id entero del periodo.
    it('con un periodo guardado que no es uuid usa el actual', function () {
        $current = Term::factory()->current()->create();
        $context = actorOf(User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create());
        session([Actor::ACTIVE_TERM => 3]);

        expect($context->activeTerm()?->is($current))->toBeTrue();
    });

    it('el campus activo por omisión es el único del alcance', function () {
        $context = actorOf(User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])->create());

        expect($context->activeCampus()?->is($this->c2))->toBeTrue();
    });

    it('no elige campus activo por omisión si el alcance tiene varios', function () {
        $user = User::factory()
            ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
            ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
            ->create();

        $context = actorOf($user);

        expect($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2'])
            ->and($context->activeCampus())->toBeNull();
    });
});

/*
| Puente de activeCampus() (#415, A1): la credencial ya no elige campus; mientras las pantallas de los demás módulos no
| pasen a WithCampusFilter, el campus es el de `?campus=` de la petición (en una petición de Livewire, el de la página,
| que viene en Referer), si es de los que ofrece campuses(); si no, el único de campuses(), o ninguno.
*/

describe('puente del campus de la pantalla', function () {
    beforeEach(function () {
        Route::middleware('web')->match(['GET', 'POST'], '/_test/active-campus', fn (Actor $context): array => ['active' => $context->activeCampus()?->code]);
        $this->both = User::factory()
            ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c1->id, 'department_id' => departmentKey(10)])
            ->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->c2->id, 'department_id' => departmentKey(10)])
            ->create();
    });

    it('toma el campus de ?campus= si es de los que ofrece', function () {
        $this->actingAs($this->both)->get('/_test/active-campus?campus=C2')->assertExactJson(['active' => 'C2']);
    });

    it('ignora un campus que no ofrece y cae en el único o en ninguno', function () {
        Campus::factory()->create(['code' => 'EAD', 'is_active' => false]);
        $onlyC2 = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])->create();

        $this->actingAs($this->both)->get('/_test/active-campus?campus=EAD')->assertExactJson(['active' => null]);
        $this->actingAs($onlyC2)->get('/_test/active-campus?campus=C1')->assertExactJson(['active' => 'C2']);
        $this->actingAs($onlyC2)->get('/_test/active-campus')->assertExactJson(['active' => 'C2']);
    });

    it('en una petición de Livewire toma el campus de la página, que viene en Referer', function () {
        $this->actingAs($this->both)
            ->withHeaders(['X-Livewire' => 'true', 'Referer' => url('/scheduling/editor?carrera=ISC&campus=C1')])
            ->post('/_test/active-campus')
            ->assertExactJson(['active' => 'C1']);
    });

    it('fuera de Livewire no lee Referer', function () {
        $this->actingAs($this->both)
            ->withHeaders(['Referer' => url('/scheduling/editor?campus=C1')])
            ->get('/_test/active-campus')
            ->assertExactJson(['active' => null]);
    });

    it('el contexto de otro usuario (forUser) no lee el campus de la petición', function () {
        Route::middleware('web')->get('/_test/other-campus', fn (Actor $context): array => ['other' => $context->forUser($this->both)->activeCampus()?->code]);

        $this->actingAs(User::factory()->admin()->create())->get('/_test/other-campus?campus=C1')->assertExactJson(['other' => null]);
    });
});

describe('ciclo de vida en el contenedor (Octane)', function () {
    it('se registra como scoped: una instancia por petición, no un singleton', function () {
        $first = app(Actor::class);

        expect(app(Actor::class))->toBe($first);

        app()->forgetScopedInstances();

        expect(app(Actor::class))->not->toBe($first);
    });

    it('no filtra el alcance ni el campus activo entre dos usuarios seguidos en la misma app', function () {
        Route::middleware('web')->get('/_test/scoped-context', fn (Actor $context): array => [
            'campuses' => $context->campuses()->pluck('code')->all(),
            'active' => $context->activeCampus()?->code,
        ]);
        // Dos campus con un puesto que no es exclusivo: la Subdirección es una sola por unidad (AZ-S7).
        $both = User::factory()
            ->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(20)])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create();
        $onlyC2 = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create();

        $this->actingAs($both)->get('/_test/scoped-context?campus=C1')->assertExactJson(['campuses' => ['C1', 'C2'], 'active' => 'C1']);

        $this->actingAs($onlyC2)->get('/_test/scoped-context')->assertExactJson(['campuses' => ['C2'], 'active' => 'C2']);
    });
});

describe('perfil activo', function () {
    /*
    | Con sesión, el contexto de quien entró responde solo con su perfil activo (spec 8.1, AZ-S4): el puesto que eligió
    | y sus nombramientos. El de otro usuario (forUser), que no tiene sesión, cuenta todos sus puestos.
    */

    it('con sesión, solo cuentan el puesto activo y sus alcances', function () {
        $user = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create();
        // Lo de D10 en C1 con otra carrera solo lo cubre el jefe; lo de la carrera 20 en C2 en otro departamento, solo
        // el coordinador. Los dos puestos tienen ViewCampusUnitCareer; ViewCareer, solo Coordinación.
        $ofJefe = Target::of(campus: $this->c1->id, unit: departmentKey(10), career: careerKey(21));
        $ofCoordinador = Target::of(campus: $this->c2->id, unit: departmentKey(11), career: careerKey(20));

        actingAsRole($user, Role::JefeDepartamento);
        $context = app(Actor::class);

        expect($context->activeRole())->toBe(Role::JefeDepartamento)
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, $ofJefe))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, $ofCoordinador))->toBeFalse()
            ->and($context->allowsAny(FakePermission::ViewCareer))->toBeFalse()
            ->and($context->heldRoles())->toBe([Role::JefeDepartamento, Role::Coordinador]);

        actingAsRole($user, Role::Coordinador);

        expect($context->activeRole())->toBe(Role::Coordinador)
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, $ofCoordinador))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, $ofJefe))->toBeFalse()
            ->and($context->allowsAny(FakePermission::ViewCareer))->toBeTrue()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C2']);
    });

    it('el perfil por omisión es el último usado y, si ya no lo tiene, el primero en el orden del enum; sin puestos, no hay perfil', function () {
        // Un jefe por departamento (AZ-S7): cada uno con el suyo.
        $holder = fn (?Role $last, int $department): User => User::factory()
            ->withRole(Role::Docente)
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey($department)])
            ->create(['last_role' => $last]);
        // Cada persona entra con una sesión nueva: el perfil de omisión queda fijo en la sesión de quien entró.
        $loginAs = function (User $user): Actor {
            session()->flush();

            return actorOf($user);
        };

        expect($loginAs($holder(Role::Coordinador, 10))->activeRole())->toBe(Role::Coordinador)
            ->and($loginAs($holder(Role::Subdireccion, 11))->activeRole())->toBe(Role::JefeDepartamento)
            ->and($loginAs($holder(null, 12))->activeRole())->toBe(Role::JefeDepartamento)
            ->and($loginAs(User::factory()->create(['last_role' => Role::Docente]))->activeRole())->toBeNull();

        $user = $holder(Role::Docente, 13);
        actingAsRole($user, Role::Coordinador);

        expect(app(Actor::class)->activeRole())->toBe(Role::Coordinador);

        actingAsRole($user, Role::Admin);

        expect(app(Actor::class)->activeRole())->toBe(Role::Docente);

        session([Actor::ACTIVE_ROLE => 'no-es-un-puesto']);

        expect(app(Actor::class)->activeRole())->toBe(Role::Docente);
    });

    it('cambiar de perfil en otra sesión no cambia el perfil de esta', function () {
        $user = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create();
        // El dispositivo A tiene su propia sesión; cada petición suya arma su contexto con el usuario recién leído.
        $sessionA = new Store('nexus_session', new ArraySessionHandler(120));
        $requestInA = fn (): Actor => new Actor(fn (): ?User => $user->fresh(), app(OrgTree::class), app(AuthorizationSnapshots::class), app(PermissionRegistry::class), $sessionA);

        expect($requestInA()->activeRole())->toBe(Role::JefeDepartamento);

        // El dispositivo B (la sesión de la prueba) cambia a Coordinación, que queda como `last_role`.
        $this->actingAs($user);
        app(SwitchRole::class)->handle(Role::Coordinador);

        expect($user->fresh()->last_role)->toBe(Role::Coordinador)
            ->and($requestInA()->activeRole())->toBe(Role::JefeDepartamento)
            ->and($sessionA->get(Actor::ACTIVE_ROLE))->toBe(Role::JefeDepartamento->value);
    });

    it('un last_role desconocido no rompe el perfil activo', function () {
        $user = User::factory()->withRole(Role::Docente)->create();
        DB::table('users')->where('id', $user->id)->update(['last_role' => 'puesto_que_ya_no_existe']);

        $this->actingAs($user->fresh());

        expect(app(Actor::class)->activeRole())->toBe(Role::Docente);
    });

    it('heldScopes da los nombramientos de todos los puestos aunque el activo sea otro, sin el de admin', function () {
        $user = User::factory()->admin()
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create();

        actingAsRole($user, Role::JefeDepartamento);
        $context = app(Actor::class);

        expect($context->activeRole())->toBe(Role::JefeDepartamento)
            ->and($context->heldScopes()->pluck('role')->all())->toBe([Role::JefeDepartamento, Role::Coordinador])
            ->and($context->heldScopes(Role::Coordinador)->pluck('career_id')->all())->toBe([fakeKey(20)]);
    });

    it('sin usuario autenticado no hay perfil activo', function () {
        expect(app(Actor::class)->activeRole())->toBeNull();
    });

    it('el contexto de otro usuario (forUser) cuenta todos sus puestos', function () {
        $other = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create(['last_role' => Role::Coordinador]);

        actingAsRole(User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->c1->id, 'career_id' => careerKey(21)])->create(), Role::Coordinador);
        $context = app(Actor::class)->forUser($other);

        expect($context->activeRole())->toBeNull()
            ->and($context->heldRoles())->toBe([Role::JefeDepartamento, Role::Coordinador])
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, Target::of(campus: $this->c1->id, unit: departmentKey(10), career: careerKey(21))))->toBeTrue()
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, Target::of(campus: $this->c2->id, unit: departmentKey(11), career: careerKey(20))))->toBeTrue()
            ->and($context->allowsAny(FakePermission::ViewCareer))->toBeTrue();
    });

    it('asRole responde con otro perfil sin tocar la sesión; sin ese puesto, no actúa con ninguno', function () {
        $user = User::factory()
            ->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10), 'campus_id' => $this->c1->id])
            ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
            ->create();

        $ofCoordinador = Target::of(campus: $this->c2->id, unit: departmentKey(11), career: careerKey(20));

        actingAsRole($user, Role::Coordinador);
        $context = app(Actor::class);
        $asJefe = $context->asRole(Role::JefeDepartamento);

        expect($asJefe->activeRole())->toBe(Role::JefeDepartamento)
            ->and($asJefe->allows(FakePermission::ViewUnit, Target::of(unit: departmentKey(10))))->toBeTrue()
            ->and($asJefe->allows(FakePermission::ViewCampusUnitCareer, $ofCoordinador))->toBeFalse()
            ->and($asJefe->campuses()->pluck('code')->all())->toBe(['C1'])
            ->and($context->activeRole())->toBe(Role::Coordinador)
            ->and($context->allows(FakePermission::ViewCampusUnitCareer, $ofCoordinador))->toBeTrue()
            ->and(session(Actor::ACTIVE_ROLE))->toBe(Role::Coordinador->value);

        $asSubdireccion = $context->asRole(Role::Subdireccion);

        expect($asSubdireccion->activeRole())->toBeNull()
            ->and($asSubdireccion->allowsAny(FakePermission::ViewAny))->toBeFalse()
            ->and($asSubdireccion->allows(FakePermission::ViewCampusUnitCareer, $ofCoordinador))->toBeFalse()
            ->and($asSubdireccion->campuses())->toBeEmpty();
    });

    it('el admin activo cubre todo; con otro perfil activo, no', function () {
        $user = User::factory()->admin()->withRole(Role::JefeDepartamento, ['department_id' => departmentKey(10)])->create();

        // Fuera del departamento del jefe, en una carrera cualquiera.
        $elsewhere = Target::of(campus: $this->c1->id, unit: departmentKey(11), career: careerKey(21));

        actingAsRole($user, Role::Admin);
        $context = app(Actor::class);

        expect($context->allows(FakePermission::ViewCampusUnitCareer, $elsewhere))->toBeTrue()
            ->and($context->allows(FakePermission::ViewUnit, Target::of(unit: departmentKey(11))))->toBeTrue()
            ->and($context->allowsAny(FakePermission::ViewCareer))->toBeTrue()
            ->and($context->campuses()->pluck('code')->all())->toBe(['C1', 'C2']);

        actingAsRole($user, Role::JefeDepartamento);

        expect($context->allows(FakePermission::ViewCampusUnitCareer, $elsewhere))->toBeFalse()
            ->and($context->allows(FakePermission::ViewUnit, Target::of(unit: departmentKey(10))))->toBeTrue()
            ->and($context->allows(FakePermission::ViewUnit, Target::of(unit: departmentKey(11))))->toBeFalse()
            ->and($context->allowsAny(FakePermission::ViewCareer))->toBeFalse()
            ->and($context->heldRoles())->toBe([Role::Admin, Role::JefeDepartamento]);
    });
});
