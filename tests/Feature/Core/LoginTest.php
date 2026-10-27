<?php

use App\Modules\Core\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
| Login por username con Fortify (ADR-0004). Los usuarios de la factory ya cambiaron su
| contraseña inicial y su contraseña es "password".
*/

it('muestra el formulario de acceso en español', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Usuario')
        ->assertSee('Contraseña')
        ->assertSee('Recordarme')
        ->assertSee('Entrar');
});

it('entra con username y contraseña correctos', function () {
    $user = User::factory()->create(['username' => '19110001']);

    $this->post('/login', ['username' => '19110001', 'password' => 'password'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('no entra con contraseña incorrecta', function () {
    User::factory()->create(['username' => '19110001']);

    $this->from('/login')
        ->post('/login', ['username' => '19110001', 'password' => 'incorrecta'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['username' => __('auth.failed')]);

    $this->assertGuest();
});

it('no entra con un username que no existe', function () {
    $this->from('/login')
        ->post('/login', ['username' => '19119999', 'password' => 'password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['username' => __('auth.failed')]);

    $this->assertGuest();
});

it('no deja entrar a un usuario inactivo y responde igual que a una credencial incorrecta', function () {
    User::factory()->create(['username' => '19110001', 'is_active' => false]);

    $this->from('/login')
        ->post('/login', ['username' => '19110001', 'password' => 'password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['username' => __('auth.failed')]);

    $this->assertGuest();
});

/**
 * Espía Hash::check (Hash::make sigue siendo el real) y devuelve los hashes contra los que se
 * comparó la contraseña.
 *
 * @return ArrayObject<int, string>
 */
function spyPasswordChecks(): ArrayObject
{
    $real = app('hash');
    $compared = new ArrayObject;

    Hash::spy()->shouldReceive('make')->andReturnUsing(fn (...$arguments) => $real->make(...$arguments));
    Hash::shouldReceive('check')->andReturnUsing(function (string $value, ?string $hash) use ($compared): bool {
        $compared[] = $hash;

        return false;
    });

    return $compared;
}

it('compara la contraseña contra un hash señuelo del mismo costo cuando el username no existe', function () {
    $existing = User::factory()->create();
    $compared = spyPasswordChecks();

    $this->post('/login', ['username' => '19119999', 'password' => 'password']);

    expect($compared)->toHaveCount(1)
        ->and(password_get_info($compared[0]))->toBe(password_get_info($existing->password));
});

it('compara la contraseña aunque el usuario esté inactivo', function () {
    $inactive = User::factory()->create(['username' => '19110001', 'is_active' => false]);
    $compared = spyPasswordChecks();

    $this->post('/login', ['username' => '19110001', 'password' => 'password']);

    expect($compared->getArrayCopy())->toBe([$inactive->password]);
});

it('entra con un username con mayúsculas tal como viene del SII', function () {
    $user = User::factory()->create(['username' => 'C19110001']);

    $this->post('/login', ['username' => 'C19110001', 'password' => 'password'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('bloquea el acceso tras cinco intentos en un minuto para el mismo username e IP', function () {
    User::factory()->create(['username' => '19110001']);

    foreach (range(1, 5) as $attempt) {
        $this->from('/login')->post('/login', ['username' => '19110001', 'password' => 'incorrecta']);
    }

    $this->from('/login')
        ->post('/login', ['username' => '19110001', 'password' => 'password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('username');

    expect(session('errors')->first('username'))->toStartWith('Demasiados intentos de acceso');

    $this->assertGuest();
});

it('no bloquea a otro username desde la misma IP', function () {
    $other = User::factory()->create(['username' => '19110002']);

    foreach (range(1, 6) as $attempt) {
        $this->from('/login')->post('/login', ['username' => '19110001', 'password' => 'incorrecta']);
    }

    $this->post('/login', ['username' => '19110002', 'password' => 'password'])->assertRedirect('/');

    $this->assertAuthenticatedAs($other);
});

it('vuelve a dejar entrar cuando pasa el minuto del bloqueo', function () {
    $user = User::factory()->create(['username' => '19110001']);

    foreach (range(1, 6) as $attempt) {
        $this->from('/login')->post('/login', ['username' => '19110001', 'password' => 'incorrecta']);
    }

    $this->travel(61)->seconds();

    $this->post('/login', ['username' => '19110001', 'password' => 'password'])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
});

it('actualiza last_login_at al entrar sin tocar updated_at', function () {
    Carbon::setTestNow('2026-11-02 08:00:00');
    $user = User::factory()->create(['username' => '19110001', 'last_login_at' => null]);

    Carbon::setTestNow('2026-11-02 08:15:00');
    $this->post('/login', ['username' => '19110001', 'password' => 'password']);

    $user = $user->fresh();

    expect($user->last_login_at->toDateTimeString())->toBe('2026-11-02 08:15:00')
        ->and($user->updated_at->toDateTimeString())->toBe('2026-11-02 08:00:00');
});

it('no cambia last_login_at cuando el acceso falla', function () {
    $user = User::factory()->create(['username' => '19110001', 'last_login_at' => null]);

    $this->post('/login', ['username' => '19110001', 'password' => 'incorrecta']);

    expect($user->fresh()->last_login_at)->toBeNull();
});

it('recuerda la sesión cuando se marca recordarme', function () {
    User::factory()->create(['username' => '19110001']);

    $this->post('/login', ['username' => '19110001', 'password' => 'password', 'remember' => '1'])
        ->assertCookie(Auth::guard()->getRecallerName());
});

it('muestra en español los mensajes de validación del acceso', function () {
    $this->from('/login')
        ->post('/login', ['username' => '', 'password' => ''])
        ->assertSessionHasErrors([
            'username' => 'El campo usuario es obligatorio.',
            'password' => 'El campo contraseña es obligatorio.',
        ]);
});

it('manda al invitado que pide la página de inicio al login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('cierra la sesión', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
});

it('no expone registro, recuperación por correo, verificación de correo, perfil ni 2FA de Fortify', function (string $route) {
    expect(Route::has($route))->toBeFalse();
})->with([
    'register',
    'register.store',
    'password.request',
    'password.email',
    'verification.notice',
    'user-profile-information.update',
    'two-factor.login',
    'passkey.login',
]);
