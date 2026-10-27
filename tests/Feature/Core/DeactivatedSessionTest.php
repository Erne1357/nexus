<?php

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/*
| Un usuario desactivado no entra (LoginTest) ni conserva la sesión que ya tenía abierta: en
| su siguiente petición se cierra y vuelve al login con un aviso que no explica el motivo.
*/

it('cierra la sesión de un usuario desactivado en su siguiente petición y lo manda al login', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertOk();

    $user->update(['is_active' => false]);

    $this->from('/')
        ->get('/')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['username' => 'Tu sesión terminó. Vuelve a iniciar sesión.']);

    $this->assertGuest();
});

it('invalida la sesión y regenera el token CSRF del usuario desactivado', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['campus_id' => 2])->get('/')->assertOk();
    $token = session()->token();

    $user->update(['is_active' => false]);
    $this->get('/');

    expect(session()->has('campus_id'))->toBeFalse()
        ->and(session()->token())->not->toBe($token);
});

it('cierra la sesión del usuario desactivado aunque tenga pendiente el cambio de contraseña', function () {
    $user = User::factory()->create(['is_active' => false, 'must_change_password' => true]);

    $this->actingAs($user)
        ->get(route('user-password.edit'))
        ->assertRedirect('/login');

    $this->assertGuest();
});

it('no cierra la sesión de un usuario activo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('no deja volver con la cookie de recordarme a un usuario desactivado ni anota su acceso', function () {
    $user = User::factory()->create(['is_active' => false, 'remember_token' => Str::random(60), 'last_login_at' => null]);

    $this->withCookie(Auth::guard()->getRecallerName(), $user->getKey().'|'.$user->getRememberToken().'|'.$user->password)
        ->get('/')
        ->assertRedirect('/login');

    $this->assertGuest();
    expect($user->fresh()->last_login_at)->toBeNull();
});

it('responde 401 a una petición JSON o de Livewire de un usuario desactivado y cierra su sesión', function (array $headers) {
    $this->actingAs(User::factory()->create(['is_active' => false]))
        ->withHeaders($headers)
        ->get('/')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Tu sesión terminó. Vuelve a iniciar sesión.']);

    $this->assertGuest();
})->with([
    'JSON' => [['Accept' => 'application/json']],
    'Livewire' => [['X-Livewire' => '1']],
]);
