<?php

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;

/*
| Cambio obligatorio de contraseña en el primer acceso (supuesto 2 de PROJECT.md): mientras
| `must_change_password` sea true, el usuario solo ve la pantalla de cambio, la envía o sale.
*/

function firstAccessUser(): User
{
    return User::factory()->create([
        'username' => '19110001',
        'password' => 'Inicial-2026',
        'must_change_password' => true,
    ]);
}

it('lleva el primer acceso a la pantalla de cambio de contraseña', function () {
    firstAccessUser();

    $this->followingRedirects()
        ->post('/login', ['username' => '19110001', 'password' => 'Inicial-2026'])
        ->assertOk()
        ->assertSee('Cambia tu contraseña');
});

it('no deja ver otras páginas hasta cambiar la contraseña', function () {
    $this->actingAs(firstAccessUser())
        ->get('/')
        ->assertRedirect(route('user-password.edit'));
});

it('deja ver la pantalla de cambio de contraseña en el primer acceso', function () {
    $this->actingAs(firstAccessUser())
        ->get(route('user-password.edit'))
        ->assertOk()
        ->assertSee('Contraseña actual')
        ->assertSee('Nueva contraseña')
        ->assertSee('Confirma la nueva contraseña')
        ->assertSee('Cerrar sesión');
});

it('deja cerrar la sesión sin cambiar la contraseña', function () {
    $this->actingAs(firstAccessUser())->post('/logout');

    $this->assertGuest();
});

it('cambia la contraseña, apaga must_change_password y lleva a la página que se pidió', function () {
    $user = firstAccessUser();

    $this->actingAs($user)->get('/');

    $this->put(route('user-password.update'), [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ])->assertRedirect('/');

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('Nueva-clave-2027', $user->password))->toBeTrue();

    $this->get('/')->assertOk();
});

it('rechaza el cambio si la contraseña actual no es correcta', function () {
    $user = firstAccessUser();

    $this->actingAs($user)
        ->from(route('user-password.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'otra',
            'password' => 'Nueva-clave-2027',
            'password_confirmation' => 'Nueva-clave-2027',
        ])
        ->assertRedirect(route('user-password.edit'))
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('sigue pidiendo el cambio después de un intento rechazado', function () {
    $user = firstAccessUser();

    $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'otra']);

    $this->get('/')->assertRedirect(route('user-password.edit'));
});

it('no deja a un invitado ver ni enviar el cambio de contraseña', function () {
    $this->get(route('user-password.edit'))->assertRedirect('/login');

    $this->put(route('user-password.update'), [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ])->assertRedirect('/login');
});

it('deja pasar a la página de inicio a quien ya cambió su contraseña', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk();
});
