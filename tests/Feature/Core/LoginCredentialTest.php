<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;

/*
| La credencial de la pantalla de acceso (tarea 1.23; DESIGN.md, "Pantalla de acceso"): el login manda el formulario
| pidiendo JSON y Fortify (LoginResponse) responde la ficha de quien entra y a dónde va, para llenar la credencial y
| llevarla a la banda antes de cambiar de página. Sin JavaScript el formulario entra como siempre (LoginTest).
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
});

it('con JSON entra y responde la ficha de la credencial, la misma de la banda, y a dónde ir', function () {
    $coordinador = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['name' => 'Carla Cota Ruiz', 'username' => '19110001']);

    $this->postJson('/login', ['username' => '19110001', 'password' => 'password'])
        ->assertOk()
        ->assertExactJson([
            'redirect' => url('/'),
            'band' => true,
            'credential' => [
                'name' => 'Carla Cota Ruiz',
                'username' => '19110001',
                'initials' => 'CC',
                'role' => 'Coordinador de carrera',
                'term' => 'Ene-Jun 2027',
                'summary' => 'Coordinador de carrera · Ene-Jun 2027',
            ],
        ]);

    $this->assertAuthenticatedAs($coordinador);
});

it('con JSON lleva a la página que se había pedido antes de entrar', function () {
    User::factory()->admin()->create(['username' => 'admin2']);

    $this->get(route('core.campuses'))->assertRedirect(route('login'));

    $this->postJson('/login', ['username' => 'admin2', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('redirect', route('core.campuses'));
});

it('con el cambio de contraseña pendiente no lleva la credencial a la banda', function () {
    User::factory()->admin()->create(['username' => 'nuevo', 'must_change_password' => true]);

    $this->postJson('/login', ['username' => 'nuevo', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('band', false);
});

it('con una descarga pendiente lleva a ella sin pasar la credencial a la banda', function () {
    User::factory()->admin()->create(['username' => 'admin2']);
    $download = url('/horarios/20271/PRES/exportes/plantilla');

    $this->get($download)->assertRedirect(route('login'));

    $this->postJson('/login', ['username' => 'admin2', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('redirect', $download)
        ->assertJsonPath('band', false);
});

it('con JSON una contraseña incorrecta responde el mismo mensaje genérico y no entra', function () {
    User::factory()->create(['username' => '19110001']);

    $this->postJson('/login', ['username' => '19110001', 'password' => 'incorrecta'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.username.0', __('auth.failed'));

    $this->assertGuest();
});

it('con JSON el límite de intentos responde 429 con el aviso de espera', function () {
    User::factory()->create(['username' => '19110001']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/login', ['username' => '19110001', 'password' => 'incorrecta']);
    }

    $response = $this->postJson('/login', ['username' => '19110001', 'password' => 'password'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    expect($response->json('errors.username.0'))->toStartWith('Demasiados intentos de acceso');
    $this->assertGuest();
});
