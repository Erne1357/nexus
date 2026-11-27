<?php

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;

/*
| La credencial de la pantalla de acceso (tarea 1.23; DESIGN.md, "Pantalla de acceso"): el login manda el formulario
| pidiendo JSON y Fortify (LoginResponse) responde la ficha de quien entra y a dónde va, para llenar la credencial y
| llevarla a la banda antes de cambiar de página. Sin JavaScript el formulario entra como siempre (LoginTest).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00', 'America/Ciudad_Juarez'));
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-05-28']);
});

it('con JSON entra y responde la ficha de la credencial, la misma de la banda, y a dónde ir con su ruta', function () {
    $coordinador = User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['name' => 'Carla Cota Ruiz', 'username' => '19110001']);

    $this->postJson('/login', ['username' => '19110001', 'password' => 'password'])
        ->assertOk()
        ->assertExactJson([
            'redirect' => url('/'),
            'band' => true,
            'page' => ['area' => null, 'title' => 'Inicio'],
            'credential' => [
                'name' => 'Carla Cota Ruiz',
                'username' => '19110001',
                'initials' => 'CC',
                'photo' => null,
                'role' => 'Coordinador de carrera',
                'term' => 'Ene-Jun 2027',
                'loadTerm' => 'Ene-Jun 2027',
                'summary' => 'Coordinador de carrera · Ene-Jun 2027',
            ],
        ]);

    $this->assertAuthenticatedAs($coordinador);
});

it('con JSON lleva a la página que se había pedido antes de entrar, con el área y el título que muestra la banda', function () {
    User::factory()->admin()->create(['username' => 'admin2']);

    $this->get(route('core.campuses'))->assertRedirect(route('login'));

    $this->postJson('/login', ['username' => 'admin2', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('redirect', route('core.campuses'))
        ->assertJsonPath('page', ['area' => NavigationArea::Administracion->label(), 'title' => 'Campus']);
});

it('con una página pedida que su puesto no ve en el menú no lleva la credencial a la banda', function () {
    User::factory()
        ->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => careerKey(20)])
        ->create(['username' => '19110001']);

    $this->get(route('core.campuses'))->assertRedirect(route('login'));

    $this->postJson('/login', ['username' => '19110001', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('redirect', route('core.campuses'))
        ->assertJsonPath('band', false)
        ->assertJsonPath('page', null);
});

it('con el cambio de contraseña pendiente no lleva la credencial a la banda', function () {
    User::factory()->admin()->create(['username' => 'nuevo', 'must_change_password' => true]);

    $this->postJson('/login', ['username' => 'nuevo', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('band', false)
        ->assertJsonPath('page', null);
});

it('con una descarga pendiente lleva a ella sin pasar la credencial a la banda', function () {
    User::factory()->admin()->create(['username' => 'admin2']);
    $download = url('/horarios/20271/PRES/exportes/plantilla');

    $this->get($download)->assertRedirect(route('login'));

    $this->postJson('/login', ['username' => 'admin2', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('redirect', $download)
        ->assertJsonPath('band', false)
        ->assertJsonPath('page', null);
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
