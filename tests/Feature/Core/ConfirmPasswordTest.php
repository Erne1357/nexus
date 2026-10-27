<?php

use App\Modules\Core\Models\User;

/*
| Confirmación de contraseña de Fortify (`password.confirm`), para las acciones sensibles que la
| pidan con el middleware del mismo nombre.
*/

it('muestra la pantalla de confirmación de contraseña en español', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('password.confirm'))
        ->assertOk()
        ->assertSee('Confirma tu contraseña')
        ->assertSee('Confirmar');
});

it('confirma con la contraseña correcta y sigue a la página que se pidió', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['url.intended' => '/'])
        ->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect('/')
        ->assertSessionHas('auth.password_confirmed_at');
});

it('no confirma con una contraseña incorrecta y avisa en español', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('password.confirm'))
        ->post(route('password.confirm.store'), ['password' => 'incorrecta'])
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors(['password' => 'La contraseña proporcionada no es correcta.'])
        ->assertSessionMissing('auth.password_confirmed_at');
});

it('no deja a un invitado ver la pantalla de confirmación', function () {
    $this->get(route('password.confirm'))->assertRedirect('/login');
});
