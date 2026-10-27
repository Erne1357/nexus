<?php

use App\Modules\Core\Models\User;

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
