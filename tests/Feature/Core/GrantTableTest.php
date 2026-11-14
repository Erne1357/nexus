<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Staffing\Enums\StaffingPermission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Authorization\FakePermission;

/*
| Roles editables (CF-01, CF-02; adenda del 14 de noviembre de 2026 a ADR-0018): GrantTable dice qué tiene cada puesto
| hoy, con sus roles activos y sus permisos sueltos. El día cero, con los roles de fábrica de la migración, es idéntico
| a lo de antes (matrix.json). Cada «petición» es una instancia nueva de GrantTable (scoped).
*/

beforeEach(function () {
    Cache::flush();
    app()->forgetScopedInstances();
});

/**
 * GrantTable de una petición nueva.
 */
function nextGrantTable(): GrantTable
{
    app()->forgetScopedInstances();

    return app(GrantTable::class);
}

function grantsVersion(): int
{
    return (int) DB::table('authz_grants_version')->value('version');
}

it('CF-02: el día cero, con los roles de fábrica, cada puesto tiene exactamente lo de matrix.json', function () {
    $registry = app(PermissionRegistry::class);
    $table = app(GrantTable::class);
    $differences = [];

    foreach ($registry->permissions() as $permission) {
        foreach (Role::cases() as $role) {
            if ($table->grants($role, $permission) !== $registry->grants($role, $permission)) {
                $differences[] = "{$role->value} → {$permission->value}";
            }
        }

        if ($table->rolesGranting($permission) !== $registry->rolesGranting($permission)) {
            $differences[] = "rolesGranting({$permission->value})";
        }
    }

    expect($differences)->toBe([])
        ->and($table->matrix())->toEqual(json_decode(file_get_contents(base_path('tests/Fixtures/Authorization/matrix.json')), true)['grants']);
});

it('CF-02: cada puesto salvo el admin tiene su rol de fábrica «<puesto> (de fábrica)», que sigue al código, y nada suelto', function () {
    $sets = PermissionSet::query()->get()->keyBy(fn (PermissionSet $set): string => $set->factory_role->value);

    expect($sets->keys()->sort()->values()->all())->toBe(collect(Role::cases())->reject(fn (Role $role): bool => $role === Role::Admin)->map->value->sort()->values()->all());

    foreach ($sets as $role => $set) {
        expect($set->name)->toBe(Role::from($role)->listLabel().' (de fábrica)')
            ->and($set->follows_factory)->toBeTrue()
            ->and($set->is_active)->toBeTrue()
            ->and(DB::table('position_permission_sets')->where('role', $role)->pluck('permission_set_id')->all())->toBe([$set->id])
            ->and(DB::table('permission_set_permissions')->where('permission_set_id', $set->id)->exists())->toBeFalse();
    }

    expect(DB::table('position_permissions')->exists())->toBeFalse();
});

it('CF-01: un puesto tiene la unión de sus roles activos y sus permisos sueltos', function () {
    PermissionSet::factory()->withPermissions([CorePermission::TermView, CorePermission::TermManageCalendar])->assignedTo(Role::Coordinador)->create();
    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingView->value]);

    $table = nextGrantTable();

    expect($table->grants(Role::Coordinador, CorePermission::TermView))->toBeTrue()
        ->and($table->grants(Role::Coordinador, CorePermission::TermManageCalendar))->toBeTrue()
        ->and($table->grants(Role::Coordinador, CorePermission::SettingView))->toBeTrue()
        ->and($table->grants(Role::Coordinador, AcademicLoadPermission::ProposalView))->toBeTrue()
        ->and($table->grants(Role::JefeProyectoDocente, CorePermission::SettingView))->toBeFalse()
        ->and($table->rolesGranting(CorePermission::SettingView))->toBe([Role::Coordinador])
        ->and($table->rolesGranting(CorePermission::TermManageCalendar))->toBe([Role::Subdireccion, Role::Coordinador]);
});

it('CF-S2: un rol desactivado o borrado no aporta nada, aunque siga asignado', function () {
    PermissionSet::factory()->inactive()->withPermissions([CorePermission::SettingView])->assignedTo(Role::Coordinador)->create();
    PermissionSet::factory()->withPermissions([CorePermission::LoginNoticeView])->assignedTo(Role::Coordinador)->create()->delete();
    PermissionSet::query()->where('factory_role', Role::Docente)->update(['is_active' => false]);

    $table = nextGrantTable();

    expect($table->grants(Role::Coordinador, CorePermission::SettingView))->toBeFalse()
        ->and($table->grants(Role::Coordinador, CorePermission::LoginNoticeView))->toBeFalse()
        ->and($table->matrix())->not->toHaveKey(Role::Docente->value)
        ->and(DB::table('position_permission_sets')->where('role', Role::Coordinador->value)->count())->toBe(3);
});

it('CF-02: un rol de fábrica que ya no sigue al código aporta solo su propia lista', function () {
    $set = PermissionSet::query()->where('factory_role', Role::Docente)->sole();
    $set->update(['follows_factory' => false]);
    DB::table('permission_set_permissions')->insert(['permission_set_id' => $set->id, 'permission' => StaffingPermission::MyLoadView->value]);

    expect(nextGrantTable()->matrix()[Role::Docente->value])->toBe([StaffingPermission::MyLoadView->value]);
});

it('CF-02: un rol que sigue al código aporta lo que el código conceda, y la llave cambia con la huella del código', function () {
    $key = nextGrantTable()->cacheKey();

    useFakePermissions();
    $table = app(GrantTable::class);

    expect($table->cacheKey())->not->toBe($key)
        ->and($table->matrix())->toEqual(app(PermissionRegistry::class)->matrix())
        ->and($table->grants(Role::Docente, FakePermission::ViewOwn))->toBeTrue()
        ->and($table->grants(Role::Admin, FakePermission::ViewCampusCareer))->toBeFalse();
});

it('CF-01: toda escritura en las cuatro tablas sube la versión, por sentencia', function () {
    $versions = [grantsVersion()];

    $set = PermissionSet::factory()->create();
    $versions[] = grantsVersion();
    DB::table('permission_set_permissions')->insert([
        ['permission_set_id' => $set->id, 'permission' => CorePermission::SettingView->value],
        ['permission_set_id' => $set->id, 'permission' => CorePermission::LoginNoticeView->value],
    ]);
    $versions[] = grantsVersion();
    DB::table('position_permission_sets')->insert(['role' => Role::Coordinador->value, 'permission_set_id' => $set->id]);
    $versions[] = grantsVersion();
    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingView->value]);
    $versions[] = grantsVersion();
    DB::table('position_permissions')->delete();
    $versions[] = grantsVersion();
    $set->update(['is_active' => false]);
    $versions[] = grantsVersion();

    expect(array_map(fn (int $version): int => $version - $versions[0], $versions))->toBe([0, 1, 2, 3, 4, 5, 6]);
});

it('CF-01: un cambio vale en la siguiente petición: la versión sube y la llave cambia', function () {
    $first = nextGrantTable();
    $key = $first->cacheKey();

    expect($first->grants(Role::Coordinador, CorePermission::SettingView))->toBeFalse()
        ->and(Cache::has($key))->toBeTrue();

    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingView->value]);

    expect($first->grants(Role::Coordinador, CorePermission::SettingView))->toBeFalse()
        ->and($first->cacheKey())->toBe($key);

    $second = nextGrantTable();

    expect($second->version())->toBe($first->version() + 1)
        ->and($second->cacheKey())->not->toBe($key)
        ->and($second->grants(Role::Coordinador, CorePermission::SettingView))->toBeTrue()
        ->and($key)->toStartWith('authz:grants:v'.GrantTable::FORMAT.':'.$first->version().':');
});

it('CF-01: la versión se lee una vez por petición y, con la misma versión, el mapa sale de la caché', function () {
    nextGrantTable()->grants(Role::Coordinador, CorePermission::SettingView);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $table = nextGrantTable();
    $table->grants(Role::Coordinador, CorePermission::SettingView);
    $table->rolesGranting(AcademicLoadPermission::ProposalView);
    $table->matrix();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'authz_grants_version'))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'permission'))->count())->toBe(0);
});

it('CF-01: lo leído dentro de una transacción que se revierte no queda en la caché', function () {
    $key = null;

    try {
        DB::transaction(function () use (&$key): void {
            DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::SettingView->value]);
            $table = nextGrantTable();
            $key = $table->cacheKey();

            expect($table->grants(Role::Coordinador, CorePermission::SettingView))->toBeTrue();

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    expect(Cache::has($key))->toBeFalse()
        ->and(nextGrantTable()->grants(Role::Coordinador, CorePermission::SettingView))->toBeFalse();
});

it('un mapa cacheado con otra forma se descarta y se vuelve a armar', function () {
    $table = nextGrantTable();
    Cache::put($table->cacheKey(), [Role::Coordinador->value => 'academic_load.proposal.view'], 60);

    expect($table->grants(Role::Coordinador, AcademicLoadPermission::ProposalView))->toBeTrue()
        ->and($table->grants(Role::Coordinador, CorePermission::SettingView))->toBeFalse();
});

it('CF-03 y CF-04: lo que la base guarde y las reglas no permitan no vale, igual que una clave que nadie declaró', function () {
    DB::table('position_permissions')->insert([
        ['role' => Role::Coordinador->value, 'permission' => CorePermission::ActivityView->value],
        ['role' => Role::Docente->value, 'permission' => CorePermission::SettingView->value],
        ['role' => Role::Coordinador->value, 'permission' => FacultyPermission::TeacherView->value],
        ['role' => Role::Coordinador->value, 'permission' => 'nadie.declaro.esto'],
    ]);

    $table = nextGrantTable();

    expect($table->grants(Role::Coordinador, CorePermission::ActivityView))->toBeFalse()
        ->and($table->grants(Role::Docente, CorePermission::SettingView))->toBeFalse()
        ->and($table->grants(Role::Coordinador, FacultyPermission::TeacherView))->toBeFalse()
        ->and($table->matrix()[Role::Coordinador->value])->toBe(app(PermissionRegistry::class)->matrix()[Role::Coordinador->value]);
});

it('CF-01: Actor y RecipientsQuery preguntan a GrantTable, y el cambio vale en la siguiente petición sin volver a entrar', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => careerKey(20)])->create();
    $this->actingAs($coordinator);

    expect(app(Actor::class)->allowsAny(CorePermission::LoginNoticeView))->toBeFalse()
        ->and(app(RecipientsQuery::class)->holders(CorePermission::LoginNoticeView, Target::of()))->toBe([]);

    DB::table('position_permissions')->insert(['role' => Role::Coordinador->value, 'permission' => CorePermission::LoginNoticeView->value]);
    app()->forgetScopedInstances();

    $holders = app(RecipientsQuery::class)->holders(CorePermission::LoginNoticeView, Target::of());

    expect(app(Actor::class)->allowsAny(CorePermission::LoginNoticeView))->toBeTrue()
        ->and(array_map(fn (array $holder): string => $holder['user']->id, $holders))->toBe([$coordinator->id])
        ->and($holders[0]['role'])->toBe(Role::Coordinador);
});
