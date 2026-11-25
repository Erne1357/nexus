<?php

use App\Modules\Core\Actions\SetStaffPhoto;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| «Recordar a la persona» en la pantalla de acceso (CO-05, tarea 1.36): con «Recordarme» el equipo guarda a quien entró
| (nexus.login.persona) y la siguiente visita muestra su foto grande y pide solo la contraseña.
*/

beforeEach(function () {
    Storage::fake('staff_photos');
    $this->person = User::factory()->create(['username' => 'maria01', 'name' => 'María Ficticia Luna']);
    app(SetStaffPhoto::class)->handle($this->person, UploadedFile::fake()->image('f.jpg', 700, 900), communicationHead());
});

/** Entra (con o sin «Recordarme»), sale y vuelve a la pantalla de acceso. */
function signInAndOut(bool $remember)
{
    $page = visit('/login')->fill('username', 'maria01')->fill('password', 'password');
    $remember && $page->check('remember');

    return $page->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->click('@credential')
        ->press('Cerrar sesión')
        ->assertPathIs('/login');
}

it('con Recordarme la siguiente visita muestra a la persona y pide solo la contraseña', function () {
    signInAndOut(remember: true)
        ->assertSee('María Ficticia Luna')
        ->assertVisible('[data-testid="login-photo"]')
        ->assertScript("JSON.parse(localStorage.getItem('nexus.login.persona')).username", 'maria01')
        ->assertScript("document.querySelector('form [name=username]').offsetParent === null", true)
        ->assertScript("document.querySelector('form [name=remember]').checked", true)
        ->assertScript('document.activeElement.name', 'password')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertNoJavaScriptErrors();
});

it('¿No eres tú? la olvida', function () {
    signInAndOut(remember: true)
        ->assertSee('María Ficticia Luna')
        ->click('¿No eres tú?')
        ->assertScript("String(localStorage.getItem('nexus.login.persona'))", 'null')
        ->assertScript("document.querySelector('form [name=username]').offsetParent !== null", true)
        ->assertScript("document.querySelector('form [name=username]').value", '')
        ->assertDontSee('María Ficticia Luna')
        ->assertNoJavaScriptErrors();
});

it('entrar sin Recordarme borra a la persona recordada', function () {
    signInAndOut(remember: true)
        ->assertScript('document.activeElement.name', 'password')
        ->assertScript("localStorage.getItem('nexus.login.persona') !== null", true)
        ->uncheck('remember')
        ->assertScript("document.querySelector('form [name=remember]').checked", false)
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->assertScript('window.Alpine?.$data(document.body).menuOpen === false')
        ->assertScript("String(localStorage.getItem('nexus.login.persona'))", 'null')
        ->assertNoJavaScriptErrors();
});

it('una foto recordada que no es una miniatura data: se ignora y quedan las iniciales', function (string $photo) {
    $page = visit('/login');
    $page->script('localStorage.setItem("nexus.login.persona", '.json_encode(json_encode(['username' => 'x', 'name' => 'Yara Ficticia', 'role' => 'z', 'initials' => 'YF', 'photo' => $photo])).')');

    $page->navigate('/login')
        ->assertSee('Yara Ficticia')
        ->assertScript("document.querySelector('[data-testid=login-photo]').getAttribute('src')", null)
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=login-photo]')).display", 'none')
        ->assertSee('YF')
        ->assertNoJavaScriptErrors();
})->with(['javascript:alert(1)', 'https://example.com/foto.jpg']);
