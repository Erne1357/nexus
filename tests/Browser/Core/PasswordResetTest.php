<?php

use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Password;

it('AC-16 a AC-18: el personal pide el enlace desde la pantalla de acceso, elige una contraseña nueva con él y entra', function () {
    $user = User::factory()->create([
        'username' => 'PEAA800101XX1',
        'email' => 'ana.perez@cdjuarez.tecnm.mx',
        'password' => 'Vieja-clave-2026',
    ]);

    visit('/login')
        ->click('¿Olvidaste tu contraseña?')
        ->assertPathIs('/forgot-password')
        ->fill('username', 'PEAA800101XX1')
        ->press('Mandar enlace')
        ->assertSee('te llegará un enlace')
        ->assertNoJavaScriptErrors();

    $token = Password::broker()->createToken($user);

    visit(route('password.reset', ['token' => $token, 'username' => 'PEAA800101XX1'], false))
        ->assertSee('Elige una contraseña nueva')
        ->fill('password', 'Nueva-clave-2027')
        ->fill('password_confirmation', 'Nueva-clave-2027')
        ->press('Guardar contraseña')
        ->assertPathIs('/login')
        ->assertSee('Tu contraseña quedó lista')
        ->fill('username', 'PEAA800101XX1')
        ->fill('password', 'Nueva-clave-2027')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
});
