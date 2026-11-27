<?php

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Support\PrivacyNotice;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Hash;

/*
| El alumno en el navegador (AC-10 a AC-12, AC-15, AC-22): entra con su número de control y su NIP corto, tiene que
| cambiarlo en ese momento aceptando el aviso de privacidad, y después ve «Mis datos».
*/

it('el alumno entra con su NIP corto, lo cambia aceptando el aviso de privacidad y abre «Mis datos»', function () {
    $student = Student::factory()->create(['control_number' => '24111234', 'name' => 'Daniela Ortiz Mendoza']);
    app(SyncStudentAccounts::class)->handle(['24111234' => '123']);

    visit('/login')
        ->fill('username', '24111234')
        ->fill('password', '123')
        ->press('Entrar')
        ->assertPathIs('/user/password')
        ->assertSee('Entraste con tu NIP del SII')
        ->assertSee('AVISO DE PRIVACIDAD SIMPLIFICADO')
        ->fill('current_password', '0123')
        ->fill('password', 'Propia-2027')
        ->fill('password_confirmation', 'Propia-2027')
        ->check('privacy_notice')
        ->press('Guardar contraseña')
        ->assertSee('Tu contraseña se actualizó.')
        ->click('Entrar a Nexus')
        ->assertPathIs('/')
        ->assertSee('Alumno')
        ->click('[data-menu-area="mi_espacio"] [data-menu-flip="icon"]')
        ->assertPathIs('/resumen')
        ->navigate('/my-record')
        ->assertSee('Daniela Ortiz Mendoza')
        ->assertSee('Ya no entras con tu NIP del SII')
        ->assertNoJavaScriptErrors();

    $user = $student->fresh()->user;
    expect($user->password_origin)->toBe(PasswordOrigin::User)
        ->and(Hash::check('Propia-2027', $user->password))->toBeTrue()
        ->and($user->privacy_notice_version)->toBe(PrivacyNotice::VERSION);
});
