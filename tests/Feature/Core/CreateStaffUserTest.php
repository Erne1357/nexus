<?php

use App\Modules\Core\Actions\CreateStaffUser;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('crea un usuario activo con la contraseña por defecto y cambio obligatorio', function () {
    $user = app(CreateStaffUser::class)->handle('docente01', 'Ana Pérez', 'ana@example.test');

    expect($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->email)->toBe('ana@example.test')
        ->and(Hash::check(app(DefaultPassword::class)->value(), $user->fresh()->password))->toBeTrue();
});

it('usa el hash dado en lugar de calcular uno', function () {
    $hash = app(DefaultPassword::class)->hash();

    $user = app(CreateStaffUser::class)->handle('docente02', 'Luis Gómez', null, $hash);

    expect($user->fresh()->password)->toBe($hash);
});

it('deja bitácora de la alta sin la contraseña', function () {
    $user = app(CreateStaffUser::class)->handle('docente03', 'Eva Ruiz');

    $log = Activity::where('event', 'created')->where('subject_id', $user->id)->first();
    $json = json_encode($log->attribute_changes);

    expect($log)->not->toBeNull()
        ->and($json)->toContain('docente03')
        ->and($json)->not->toContain(app(DefaultPassword::class)->value())
        ->and($json)->not->toContain($user->fresh()->password);
});

it('rechaza un username repetido', function () {
    User::factory()->create(['username' => 'docente04']);

    expect(fn () => app(CreateStaffUser::class)->handle('docente04', 'Otro'))
        ->toThrow(ValidationException::class);

    try {
        app(CreateStaffUser::class)->handle('docente04', 'Otro');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('username');
    }
});

it('no crea usuarios sin contraseña si falta la variable', function () {
    config(['auth.default_password' => null]);

    expect(fn () => app(CreateStaffUser::class)->handle('docente05', 'Sin Clave'))
        ->toThrow(RuntimeException::class, 'NEXUS_DEFAULT_PASSWORD');

    expect(User::where('username', 'docente05')->exists())->toBeFalse();
});
