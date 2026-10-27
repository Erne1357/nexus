<?php

use App\Modules\Core\Actions\UpdateUserPassword;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Spatie\Activitylog\Models\Activity;

/*
| La Action de Core que Fortify usa para cambiar la contraseña (contrato UpdatesUserPasswords).
| Corre con base de datos porque valida la contraseña actual y escribe en users y en la bitácora.
*/

beforeEach(function () {
    $this->user = User::factory()->create(['password' => 'Inicial-2026', 'must_change_password' => true]);
    $this->actingAs($this->user);
});

/**
 * @param  array<string, string>  $input
 */
function updatePassword(User $user, array $input): void
{
    app(UpdateUserPassword::class)->update($user, $input);
}

function validationErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('es la Action que Fortify usa para cambiar contraseñas', function () {
    expect(app(UpdatesUserPasswords::class))->toBeInstanceOf(UpdateUserPassword::class);
});

it('cambia la contraseña y apaga must_change_password', function () {
    updatePassword($this->user, [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ]);

    $user = $this->user->fresh();

    expect(Hash::check('Nueva-clave-2027', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse();
});

it('exige la contraseña actual', function (array $input) {
    $errors = validationErrors(fn () => updatePassword($this->user, $input + [
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ]));

    expect($errors)->toHaveKey('current_password')
        ->and(Hash::check('Inicial-2026', $this->user->fresh()->password))->toBeTrue();
})->with([
    'sin contraseña actual' => [[]],
    'con una contraseña actual incorrecta' => [['current_password' => 'otra-clave-1']],
]);

it('exige que la nueva contraseña esté confirmada', function (array $input) {
    $errors = validationErrors(fn () => updatePassword($this->user, $input + [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
    ]));

    expect($errors)->toHaveKey('password')
        ->and($this->user->fresh()->must_change_password)->toBeTrue();
})->with([
    'sin confirmación' => [[]],
    'con una confirmación distinta' => [['password_confirmation' => 'Nueva-clave-2028']],
]);

it('aplica la política de contraseñas: al menos 8 caracteres con letras y números', function (string $password) {
    $errors = validationErrors(fn () => updatePassword($this->user, [
        'current_password' => 'Inicial-2026',
        'password' => $password,
        'password_confirmation' => $password,
    ]));

    expect($errors)->toHaveKey('password');
})->with([
    'menos de 8 caracteres' => 'Clave-1',
    'sin números' => 'Clave-nueva',
    'sin letras' => '20272027',
]);

it('no acepta como nueva la misma contraseña actual', function () {
    $errors = validationErrors(fn () => updatePassword($this->user, [
        'current_password' => 'Inicial-2026',
        'password' => 'Inicial-2026',
        'password_confirmation' => 'Inicial-2026',
    ]));

    expect($errors)->toHaveKey('password')
        ->and($this->user->fresh()->must_change_password)->toBeTrue();
});

it('muestra en español los errores de la contraseña nueva', function () {
    $errors = validationErrors(fn () => updatePassword($this->user, [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
    ]));

    expect($errors['password'])->toContain('La confirmación de contraseña no coincide.');
});

it('registra el cambio en la bitácora sin guardar la contraseña', function () {
    updatePassword($this->user, [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ]);

    $entry = Activity::sole();
    $stored = json_encode($entry->getAttributes());

    expect($entry->event)->toBe('password-changed')
        ->and($entry->subject->is($this->user))->toBeTrue()
        ->and($entry->causer->is($this->user))->toBeTrue()
        ->and($stored)->not->toContain('Nueva-clave-2027')
        ->and($stored)->not->toContain('Inicial-2026')
        ->and($stored)->not->toContain($this->user->fresh()->password);
});

it('no escribe en la bitácora si el cambio se rechaza', function () {
    validationErrors(fn () => updatePassword($this->user, ['current_password' => 'otra-clave-1']));

    expect(Activity::count())->toBe(0);
});
