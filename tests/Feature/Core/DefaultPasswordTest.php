<?php

use App\Modules\Core\Actions\RequirePasswordChange;
use App\Modules\Core\Actions\ResetUserPassword;
use App\Modules\Core\Actions\UpdateUserPassword;
use App\Modules\Core\Events\UserPasswordReset;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Contraseña por defecto de los usuarios nuevos (NEXUS_DEFAULT_PASSWORD; en pruebas, phpunit.xml).
*/

it('hashea la contraseña por defecto una sola vez por instancia', function () {
    $default = app(DefaultPassword::class);

    expect($default->hash())->toBe($default->hash())
        ->and(Hash::check($default->value(), $default->hash()))->toBeTrue();
});

it('obliga a cambiar la contraseña a quien entra con la de defecto', function () {
    $user = User::factory()->create(['username' => '19110001', 'password' => app(DefaultPassword::class)->value()]);

    $this->followingRedirects()
        ->post('/login', ['username' => '19110001', 'password' => app(DefaultPassword::class)->value()])
        ->assertSee('Cambia tu contraseña');

    expect($user->fresh()->must_change_password)->toBeTrue();
    expect(Activity::where('event', 'password-change-required')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('no obliga a cambiarla a quien entra con otra contraseña', function () {
    $user = User::factory()->create(['username' => '19110001']);

    $this->post('/login', ['username' => '19110001', 'password' => 'password'])->assertRedirect('/');

    expect($user->fresh()->must_change_password)->toBeFalse();
});

it('no deja elegir de nuevo la contraseña por defecto', function () {
    $user = User::factory()->create(['must_change_password' => true]);
    $default = app(DefaultPassword::class)->value();

    $this->actingAs($user);

    try {
        app(UpdateUserPassword::class)->update($user, [
            'current_password' => 'password',
            'password' => $default,
            'password_confirmation' => $default,
        ]);
        $this->fail('Debió rechazar la contraseña por defecto.');
    } catch (ValidationException $e) {
        expect($e->errors()['password'])->toContain('No puedes usar la contraseña por defecto.');
    }

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('RequirePasswordChange no repite el aviso si ya estaba obligado', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    app(RequirePasswordChange::class)->handle($user);

    expect(Activity::where('event', 'password-change-required')->count())->toBe(0);
});

it('ResetUserPassword vuelve a la de defecto, obliga a cambiarla y cierra las demás sesiones', function () {
    $user = User::factory()->create(['remember_token' => 'viejo']);
    $oldHash = $user->password;

    app(ResetUserPassword::class)->handle($user);

    $fresh = $user->fresh();
    expect($fresh->must_change_password)->toBeTrue()
        ->and($fresh->password)->not->toBe($oldHash)
        ->and(Hash::check(app(DefaultPassword::class)->value(), $fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->not->toBe('viejo');

    $log = Activity::where('event', 'password-reset')->where('subject_id', $user->id)->first();
    expect($log)->not->toBeNull()
        ->and(json_encode([$log->attribute_changes, $log->properties]))->not->toContain(app(DefaultPassword::class)->value());
});

it('ResetUserPassword atribuye el reinicio a quien lo hizo y avisa con UserPasswordReset', function () {
    Event::fake([UserPasswordReset::class]);
    $admin = User::factory()->create();
    $user = User::factory()->create();

    app(ResetUserPassword::class)->handle($user, $admin);

    $log = Activity::where('event', 'password-reset')->where('subject_id', $user->id)->sole();
    expect($log->causer_id)->toBe($admin->id);
    Event::assertDispatched(UserPasswordReset::class, fn ($e) => $e->user->is($user) && $e->actor->is($admin));
});

it('ResetUserPassword sin actor no atribuye el reinicio a nadie', function () {
    $user = User::factory()->create();

    app(ResetUserPassword::class)->handle($user);

    expect(Activity::where('event', 'password-reset')->sole()->causer_id)->toBeNull();
});

it('RequirePasswordChange marca al usuario y deja una sola entrada aunque se repita', function () {
    $user = User::factory()->create();

    app(RequirePasswordChange::class)->handle($user);
    app(RequirePasswordChange::class)->handle($user);

    expect($user->fresh()->must_change_password)->toBeTrue()
        ->and(Activity::where('event', 'password-change-required')->count())->toBe(1);
});
