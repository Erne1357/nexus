<?php

use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;

it('el admin sembrado entra, cambia su contraseña inicial y llega a la página de inicio', function () {
    config(['auth.admin_initial_password' => 'Inicial-2026']);
    $this->seed(DatabaseSeeder::class);

    $page = visit('/login');

    $page->fill('username', 'admin')
        ->fill('password', 'Inicial-2026')
        ->press('Entrar')
        ->assertPathIs('/user/password')
        ->assertSee('Cambia tu contraseña')
        ->fill('current_password', 'Inicial-2026')
        ->fill('password', 'Nueva-clave-2027')
        ->fill('password_confirmation', 'Nueva-clave-2027')
        ->press('Guardar contraseña')
        ->assertPathIs('/')
        ->assertSee('Nexus')
        ->assertNoJavaScriptErrors();

    expect(User::where('username', 'admin')->sole()->must_change_password)->toBeFalse();
});
