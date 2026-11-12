<?php

use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['auth.admin_initial_password' => 'Inicial-2026!']);
});

it('crea los cinco campus con su modalidad', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Campus::orderBy('id')->get()->mapWithKeys(fn (Campus $campus) => [$campus->code => $campus->modality])->all())->toBe([
        'C1' => Modality::Presencial,
        'C2' => Modality::Presencial,
        'EAD' => Modality::Mixta,
        'ENL' => Modality::EnLinea,
        'TNMV' => Modality::VirtualTecnm,
    ]);
});

it('deja activos solo Campus I y Campus II; las otras modalidades esperan a la fase 10', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Campus::orderBy('id')->pluck('is_active', 'code')->all())->toBe([
        'C1' => true,
        'C2' => true,
        'EAD' => false,
        'ENL' => false,
        'TNMV' => false,
    ]);
});

it('guarda el perfil de flujo completo en settings de cada campus', function () {
    $this->seed(DatabaseSeeder::class);

    Campus::all()->each(fn (Campus $campus) => expect($campus->settings)->toHaveKeys(array_keys(CampusProfile::DEFAULTS)));
});

it('siembra el perfil presencial de PROJECT.md en Campus I y Campus II', function (string $code) {
    $this->seed(DatabaseSeeder::class);

    $profile = Campus::where('code', $code)->sole()->profile;

    expect($profile->usesRooms)->toBeTrue()
        ->and($profile->usesTimeBlocks)->toBeTrue()
        ->and($profile->staffingMode)->toBe(StaffingMode::Department)
        ->and($profile->blockMinutes)->toBe(60)
        ->and($profile->dayStart)->toBe('07:00')
        ->and($profile->dayEnd)->toBe('21:00')
        ->and($profile->days)->toBe([1, 2, 3, 4, 5])
        ->and($profile->requiresSubdireccionApproval)->toBeFalse();
})->with(['C1', 'C2']);

it('ningún campus siembra heads_edit_rooms (decisión 5)', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Campus::all()->filter(fn (Campus $campus): bool => array_key_exists('heads_edit_rooms', $campus->settings ?? []))->all())->toBe([]);
});

it('siembra EAD con clases de lunes a sábado y docentes propios', function () {
    $this->seed(DatabaseSeeder::class);

    $profile = Campus::where('code', 'EAD')->sole()->profile;

    expect($profile->days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($profile->allowExternalTeachers)->toBeFalse();
});

it('siembra TecNM Virtual con docentes externos', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Campus::where('code', 'TNMV')->sole()->profile->allowExternalTeachers)->toBeTrue();
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
    Campus::where('code', 'C2')->sole()->update(['settings' => ['requires_subdireccion_approval' => true]]);
    Campus::where('code', 'ENL')->sole()->delete();

    $this->app->detectEnvironment(fn () => 'production');
    config(['auth.admin_initial_password' => null]);
    $this->app->make(DatabaseSeeder::class)->run();

    expect(Campus::withTrashed()->count())->toBe(5)
        ->and(Campus::where('code', 'ENL')->exists())->toBeFalse()
        ->and(Campus::where('code', 'C2')->sole()->profile->requiresSubdireccionApproval)->toBeTrue()
        ->and(User::where('username', 'admin')->count())->toBe(1)
        ->and(Hash::check('Inicial-2026!', User::where('username', 'admin')->sole()->password))->toBeTrue();
});

it('liga cada campus a su modalidad del catálogo, también uno existente que no la tenía (puente hasta contraer, ADR-0025)', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $c2->forceFill(['modality_id' => null])->saveQuietly();

    $this->seed(CampusSeeder::class);

    expect(Campus::withTrashed()->with('catalogModality')->get()->mapWithKeys(fn (Campus $campus): array => [$campus->code => $campus->catalogModality?->code])->all())
        ->toEqualCanonicalizing(['C1' => 'PRES', 'C2' => 'PRES', 'EAD' => 'EAD', 'ENL' => 'ENL', 'TNMV' => 'TNMV']);
});
