<?php

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Hash;

/*
| El alumno en el navegador (AC-10 a AC-12, AC-15): entra con su número de control y su NIP corto, ve «Mis datos» y
| cambia su NIP por una contraseña propia.
*/

it('el alumno entra con su NIP corto, abre «Mis datos» y cambia su NIP por una contraseña propia', function () {
    $student = Student::factory()->create(['control_number' => '24111234', 'name' => 'Daniela Ortiz Mendoza']);
    app(SyncStudentAccounts::class)->handle(['24111234' => '123']);

    visit('/login')
        ->fill('username', '24111234')
        ->fill('password', '123')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertSee('Alumno')
        ->click('[data-menu-area="mi_espacio"] [data-menu-flip="icon"]')
        ->assertPathIs('/my-record')
        ->assertSee('Daniela Ortiz Mendoza')
        ->assertSee('Entras con tu NIP del SII')
        ->click('@change-password')
        ->assertPathIs('/user/password')
        ->fill('current_password', '0123')
        ->fill('password', 'Propia-2027')
        ->fill('password_confirmation', 'Propia-2027')
        ->press('Guardar contraseña')
        ->assertSee('Tu contraseña se actualizó.')
        ->assertNoJavaScriptErrors();

    $user = $student->fresh()->user;
    expect($user->password_origin)->toBe(PasswordOrigin::User)
        ->and(Hash::check('Propia-2027', $user->password))->toBeTrue();
});
