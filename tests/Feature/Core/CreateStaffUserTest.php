<?php

use App\Modules\Core\Actions\CreateStaffUser;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('AC-05: el alta nace activa, sin contraseña, con cambio obligatorio y sin acceso, aunque falte NEXUS_DEFAULT_PASSWORD', function () {
    config(['auth.default_password' => null]);

    $user = app(CreateStaffUser::class)->handle('docente02', 'Luis Gómez', email: 'luis@example.test');

    expect($user->fresh())
        ->password->toBeNull()
        ->password_origin->toBeNull()
        ->is_active->toBeTrue()
        ->must_change_password->toBeTrue()
        ->email->toBe('luis@example.test')
        ->and(UserAccess::of($user->fresh()))->toBe(UserAccess::None);

    $log = Activity::where('event', 'created')->where('subject_id', $user->id)->sole();

    expect($log->description)->toBe('Dio de alta al usuario docente02, sin acceso');
});

it('AC-05: la Action no ofrece dar acceso con la contraseña por defecto', function () {
    $names = collect((new ReflectionMethod(CreateStaffUser::class, 'handle'))->getParameters())->map->getName()->all();

    expect($names)->toBe(['username', 'name', 'email']);
});

it('deja bitácora de la alta sin contraseña', function () {
    $user = app(CreateStaffUser::class)->handle('docente03', 'Eva Ruiz');

    $log = Activity::where('event', 'created')->where('subject_id', $user->id)->first();
    $json = json_encode($log->attribute_changes);

    expect($log)->not->toBeNull()
        ->and($json)->toContain('docente03')
        ->and($json)->not->toContain('"password"');
});

it('CF-S3: un correo que otro usuario ya tiene, sin importar mayúsculas, es un error del correo y no del usuario', function () {
    User::factory()->create(['email' => 'Ana.Perez@cdjuarez.tecnm.mx']);

    expect(fn () => app(CreateStaffUser::class)->handle('docente05', 'Ana', email: 'ana.perez@cdjuarez.tecnm.mx'))
        ->toThrow(ValidationException::class, 'Otro usuario ya tiene el correo ana.perez@cdjuarez.tecnm.mx.');

    expect(User::where('username', 'docente05')->exists())->toBeFalse();
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
