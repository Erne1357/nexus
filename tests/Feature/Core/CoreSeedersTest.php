<?php

use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['auth.admin_initial_password' => 'Inicial-2026!']);
});

it('MS3: siembra Campus I y Campus II, activos y solo como lugares: EAD, En línea y TecNM Virtual son modalidades (M2)', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Campus::withTrashed()->orderBy('code')->get()->map(fn (Campus $campus): array => [$campus->code, $campus->name, $campus->is_active])->all())->toBe([
        ['C1', 'Campus I', true],
        ['C2', 'Campus II', true],
    ]);
});

it('crea el admin activo, con nombramiento admin, la contraseña inicial configurada y obligado a cambiarla', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('username', 'admin')->sole();

    expect($admin->is_active)->toBeTrue()
        ->and($admin->must_change_password)->toBeTrue()
        ->and($admin->roleAssignments()->current()->where('role', Role::Admin)->exists())->toBeTrue()
        ->and(Hash::check('Inicial-2026!', $admin->password))->toBeTrue();
});

it('AdminUserSeeder da al admin un nombramiento admin sin alcance, desde hoy y sin duplicarlo', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(AdminUserSeeder::class);

    $assignments = User::where('username', 'admin')->sole()->roleAssignments()->get();

    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()->role)->toBe(Role::Admin)
        ->and($assignments->first()->campus_id)->toBeNull()
        ->and($assignments->first()->org_unit_id)->toBeNull()
        ->and($assignments->first()->career_id)->toBeNull()
        ->and($assignments->first()->valid_from->toDateString())->toBe(today()->toDateString())
        ->and($assignments->first()->valid_to)->toBeNull();
});

it('genera y muestra una contraseña aleatoria para el admin en local o testing si no se configura', function () {
    config(['auth.admin_initial_password' => null]);

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class])
        ->expectsOutputToContain('ADMIN_INITIAL_PASSWORD')
        ->assertSuccessful();

    $admin = User::where('username', 'admin')->sole();

    expect($admin->must_change_password)->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeFalse()
        ->and(Hash::check('', $admin->password))->toBeFalse();
});

it('exige ADMIN_INITIAL_PASSWORD para crear el admin fuera de local y testing', function () {
    $this->seed(DatabaseSeeder::class);
    User::where('username', 'admin')->sole()->forceFill(['username' => 'admin-anterior'])->save();

    $this->app->detectEnvironment(fn () => 'production');
    config(['auth.admin_initial_password' => null]);

    expect(fn () => $this->app->make(AdminUserSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'ADMIN_INITIAL_PASSWORD');

    expect(User::where('username', 'admin')->exists())->toBeFalse();
});

it('no duplica ni pisa lo sembrado al volver a sembrar', function () {
    $this->seed(DatabaseSeeder::class);
    Campus::where('code', 'C2')->sole()->update(['name' => 'Campus Dos']);
    Campus::where('code', 'C1')->sole()->delete();

    $this->app->detectEnvironment(fn () => 'production');
    config(['auth.admin_initial_password' => null]);
    $this->app->make(DatabaseSeeder::class)->run();

    expect(Campus::withTrashed()->count())->toBe(2)
        ->and(Campus::where('code', 'C1')->exists())->toBeFalse()
        ->and(Campus::where('code', 'C2')->sole()->name)->toBe('Campus Dos')
        ->and(User::where('username', 'admin')->count())->toBe(1)
        ->and(Hash::check('Inicial-2026!', User::where('username', 'admin')->sole()->password))->toBeTrue();
});
