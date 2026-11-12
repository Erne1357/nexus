<?php

use App\Modules\Core\Actions\CreateStaffUser;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('crea un usuario activo con la contraseña por defecto y cambio obligatorio', function () {
    $user = app(CreateStaffUser::class)->handle('docente01', 'Ana Pérez', withAccess: true, email: 'ana@example.test');

    expect($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->email)->toBe('ana@example.test')
        ->and(Hash::check(app(DefaultPassword::class)->value(), $user->fresh()->password))->toBeTrue();
});

it('AC-05: el alta manual nace con la contraseña por defecto', function () {
    $user = app(CreateStaffUser::class)->handle('personal01', 'Luis Gómez', withAccess: true);

    expect(UserAccess::of($user->fresh()))->toBe(UserAccess::MustChangePassword);
});

it('AC-02: sin acceso nace sin contraseña, activo y con cambio obligatorio, aunque falte NEXUS_DEFAULT_PASSWORD', function () {
    config(['auth.default_password' => null]);

    $user = app(CreateStaffUser::class)->handle('docente02', 'Luis Gómez', withAccess: false);

    expect($user->fresh())
        ->password->toBeNull()
        ->is_active->toBeTrue()
        ->must_change_password->toBeTrue()
        ->and(UserAccess::of($user->fresh()))->toBe(UserAccess::None);

    $log = Activity::where('event', 'created')->where('subject_id', $user->id)->sole();

    expect($log->description)->toBe('Dio de alta al usuario docente02, sin acceso');
});

it('deja bitácora de la alta sin la contraseña', function () {
    $user = app(CreateStaffUser::class)->handle('docente03', 'Eva Ruiz', withAccess: true);

    $log = Activity::where('event', 'created')->where('subject_id', $user->id)->first();
    $json = json_encode($log->attribute_changes);

    expect($log)->not->toBeNull()
        ->and($json)->toContain('docente03')
        ->and($json)->not->toContain(app(DefaultPassword::class)->value())
        ->and($json)->not->toContain($user->fresh()->password);
});

it('rechaza un username repetido', function () {
    User::factory()->create(['username' => 'docente04']);

    expect(fn () => app(CreateStaffUser::class)->handle('docente04', 'Otro', withAccess: false))
        ->toThrow(ValidationException::class);

    try {
        app(CreateStaffUser::class)->handle('docente04', 'Otro', withAccess: false);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('username');
    }
});

it('no crea usuarios sin contraseña si falta la variable', function () {
    config(['auth.default_password' => null]);

    expect(fn () => app(CreateStaffUser::class)->handle('docente05', 'Sin Clave', withAccess: true))
        ->toThrow(RuntimeException::class, 'NEXUS_DEFAULT_PASSWORD');

    expect(User::where('username', 'docente05')->exists())->toBeFalse();
});

it('AC-02: quien da de alta decide siempre si el usuario nace con acceso: el parámetro no tiene valor por omisión', function () {
    $parameter = collect((new ReflectionMethod(CreateStaffUser::class, 'handle'))->getParameters())->firstWhere('name', 'withAccess');

    expect($parameter)->not->toBeNull()
        ->and($parameter->isOptional())->toBeFalse();
});
