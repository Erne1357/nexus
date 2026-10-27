<?php

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

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

it('cambia la contraseña, apaga must_change_password y lleva a la página de inicio', function () {
    $user = firstAccessUser();

    $this->actingAs($user)
        ->from(route('user-password.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'Inicial-2026',
            'password' => 'Nueva-clave-2027',
            'password_confirmation' => 'Nueva-clave-2027',
        ])
        ->assertRedirect('/');

    $user->refresh();

    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('Nueva-clave-2027', $user->password))->toBeTrue();

    $this->get('/')->assertOk();
});

it('después del cambio lleva a la página que había pedido', function () {
    Route::middleware(['web', 'auth'])->get('/carga-de-prueba', fn () => 'carga');

    $this->actingAs(firstAccessUser())
        ->get('/carga-de-prueba')
        ->assertRedirect(route('user-password.edit'));

    $this->from(route('user-password.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'Inicial-2026',
            'password' => 'Nueva-clave-2027',
            'password_confirmation' => 'Nueva-clave-2027',
        ])
        ->assertRedirect('/carga-de-prueba');
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

it('cierra las demás sesiones del usuario al cambiar la contraseña', function () {
    $user = User::factory()->create(['password' => 'Inicial-2026']);
    $otherDevice = [Auth::guard()->getName() => $user->getKey(), 'password_hash_web' => $user->password];

    $this->withSession($otherDevice)->get('/')->assertOk();

    Auth::forgetGuards();
    $this->flushSession();
    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ]);

    Auth::forgetGuards();
    $this->flushSession();
    $this->withSession($otherDevice)->get('/')->assertRedirect('/login');

    $this->assertGuest();
});

it('mantiene activa la sesión desde la que se cambió la contraseña', function () {
    $user = User::factory()->create(['username' => '19110001', 'password' => 'Inicial-2026']);

    $this->post('/login', ['username' => '19110001', 'password' => 'Inicial-2026']);
    $this->from(route('user-password.edit'))->put(route('user-password.update'), [
        'current_password' => 'Inicial-2026',
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ]);

    Auth::forgetGuards();
    $this->get('/')->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('en un cambio voluntario regresa a la pantalla de cambio y avisa que la contraseña se actualizó', function () {
    $user = User::factory()->create(['password' => 'Inicial-2026']);

    $this->actingAs($user)
        ->from(route('user-password.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'Inicial-2026',
            'password' => 'Nueva-clave-2027',
            'password_confirmation' => 'Nueva-clave-2027',
        ])
        ->assertRedirect(route('user-password.edit'))
        ->assertSessionHas('status', 'password-updated');

    $this->get(route('user-password.edit'))->assertSee('Tu contraseña se actualizó.');
});

it('responde 403 a una petición JSON o de Livewire mientras la contraseña siga pendiente', function (array $headers) {
    $this->actingAs(firstAccessUser())
        ->withHeaders($headers)
        ->get('/')
        ->assertForbidden()
        ->assertJson(['message' => 'Cambia tu contraseña para continuar.']);
})->with([
    'JSON' => [['Accept' => 'application/json']],
    'Livewire' => [['X-Livewire' => '1']],
]);
