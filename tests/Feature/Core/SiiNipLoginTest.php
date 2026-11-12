<?php

use App\Modules\Core\Actions\AcceptAccessInvitation;
use App\Modules\Core\Actions\CreateStaffUser;
use App\Modules\Core\Actions\ResetUserPassword;
use App\Modules\Core\Actions\UpdateUserPassword;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Core\Support\SiiNip;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
| El alumno entra con su NIP del SII (AC-10). Un NIP corto sirve en sus dos formas mientras siga con el del SII
| (AC-11); el origen de la contraseña lo decide (AC-12), y el hash del NIP, de costo menor, se rehace con el normal en
| su primer inicio de sesión correcto (AC-14).
*/

beforeEach(function () {
    config(['auth.default_password' => 'Defecto-2026']);
});

function studentWithNip(string $nip, PasswordOrigin $origin = PasswordOrigin::SiiNip): User
{
    return User::factory()->create([
        'username' => '21110001',
        'password' => SiiNip::hash($nip),
        'password_origin' => $origin,
        'must_change_password' => false,
    ]);
}

it('AC-11: el alumno con NIP del SII de 3 dígitos entra con 123 y con 0123', function (string $typed) {
    $user = studentWithNip('123');

    $this->post('/login', ['username' => '21110001', 'password' => $typed])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
})->with(['123', '0123']);

it('AC-11: un NIP corto que no es el suyo no entra y recibe el mensaje genérico', function () {
    studentWithNip('123');

    $this->from('/login')
        ->post('/login', ['username' => '21110001', 'password' => '124'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['username' => __('auth.failed')]);

    $this->assertGuest();
});

it('AC-11: solo se completa con ceros mientras el origen sea el NIP del SII', function () {
    studentWithNip('0123', PasswordOrigin::User);

    $this->from('/login')->post('/login', ['username' => '21110001', 'password' => '123'])->assertRedirect('/login');

    $this->assertGuest();
});

it('AC-14: rehace con el costo normal el hash del NIP en el primer inicio correcto, sin cambiar su origen', function () {
    $user = studentWithNip('123');
    config(['hashing.bcrypt.rounds' => 5]);
    Hash::forgetDrivers();

    expect(password_get_info($user->password)['options']['cost'])->toBe(4);

    $this->post('/login', ['username' => '21110001', 'password' => '123'])->assertRedirect('/');

    $user->refresh();
    expect(password_get_info($user->password)['options']['cost'])->toBe(5)
        ->and(Hash::check('0123', $user->password))->toBeTrue()
        ->and($user->password_origin)->toBe(PasswordOrigin::SiiNip);
});

it('AC-14: guarda el NIP con costo 10, o con el configurado si es menor', function () {
    expect(SiiNip::ROUNDS)->toBe(10)
        ->and(SiiNip::rounds())->toBe(4);

    config(['hashing.bcrypt.rounds' => 12]);

    expect(SiiNip::rounds())->toBe(10);
});

it('AC-12: al cambiar su NIP la contraseña queda como propia, y la actual acepta el NIP corto', function () {
    $user = studentWithNip('123');

    app(UpdateUserPassword::class)->update($user, [
        'current_password' => '123',
        'password' => 'Propia-2027',
        'password_confirmation' => 'Propia-2027',
    ]);

    $user->refresh();
    expect($user->password_origin)->toBe(PasswordOrigin::User)
        ->and(Hash::check('Propia-2027', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse();
});

it('AC-12: la contraseña actual equivocada se rechaza con el mensaje de siempre', function () {
    $user = studentWithNip('123');

    expect(fn () => app(UpdateUserPassword::class)->update($user, [
        'current_password' => '321',
        'password' => 'Propia-2027',
        'password_confirmation' => 'Propia-2027',
    ]))->toThrow(ValidationException::class, __('validation.current_password'));

    expect($user->fresh()->password_origin)->toBe(PasswordOrigin::SiiNip);
});

it('AC-12: el alta manual, el reinicio del admin y la invitación aceptada dejan la contraseña por defecto como origen', function () {
    $created = app(CreateStaffUser::class)->handle('ABCD800101XX1', 'Ana Pérez');
    $withoutAccess = app(CreateStaffUser::class)->handle('ABCD800101XX2', 'Beto Pérez', withAccess: false);

    $student = studentWithNip('123');
    app(ResetUserPassword::class)->handle($student);

    $invited = User::factory()->create(['email' => 'carla@cdjuarez.tecnm.mx', 'password' => null, 'password_origin' => null, 'access_invited_at' => now()->startOfSecond()]);
    $hash = Str::before(Str::after(app(AccessInvitationLink::class)->url($invited), "/access/{$invited->id}/"), '?');
    app(AcceptAccessInvitation::class)->handle($invited->id, $hash);

    expect($created->fresh()->password_origin)->toBe(PasswordOrigin::Default)
        ->and($withoutAccess->fresh()->password_origin)->toBeNull()
        ->and($student->fresh()->password_origin)->toBe(PasswordOrigin::Default)
        ->and($invited->fresh()->password_origin)->toBe(PasswordOrigin::Default);
});
