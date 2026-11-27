<?php

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\PrivacyNotice;
use App\Modules\Core\Support\SiiNip;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

/*
| AC-12 (confirmado el 26 de noviembre de 2026): el alumno que entra con su NIP del SII tiene que cambiarlo en ese
| momento. AC-22: al cambiarlo acepta el aviso de privacidad simplificado del TecNM, y el alumno que no ha aceptado la
| versión vigente lo acepta antes de usar Nexus (supuesto por confirmar); el personal no lo acepta por ahora.
*/

beforeEach(function () {
    $this->activityBase = (int) Activity::max('id');
});

/**
 * Un alumno (solo el puesto `alumno`) que todavía entra con su NIP del SII y no ha aceptado el aviso.
 */
function nipStudentForNotice(string $nip = '4821'): User
{
    return User::factory()->withRole(Role::Alumno)->withoutPrivacyNotice()->create([
        'username' => '21110001',
        'password' => SiiNip::hash($nip),
        'password_origin' => PasswordOrigin::SiiNip,
        'must_change_password' => false,
    ]);
}

/**
 * Un alumno que ya cambió su NIP por una contraseña propia pero no ha aceptado el aviso vigente.
 */
function ownPasswordStudentForNotice(): User
{
    return User::factory()->withRole(Role::Alumno)->withoutPrivacyNotice()->create(['username' => '21110002']);
}

/**
 * @return array<string, string>
 */
function newPasswordForNotice(array $extra = []): array
{
    return ['current_password' => '4821', 'password' => 'Propia-2027', 'password_confirmation' => 'Propia-2027', ...$extra];
}

it('AC-12: el alumno que entra con su NIP del SII va directo a cambiarlo', function () {
    $this->actingAs(nipStudentForNotice())
        ->get(route('core.summary'))
        ->assertRedirect(route('user-password.edit'));
});

it('AC-12: con el NIP del SII, una petición JSON o de Livewire recibe 403', function () {
    $student = nipStudentForNotice();

    $this->actingAs($student)->getJson(route('core.summary'))->assertForbidden()->assertJsonPath('message', 'Cambia tu contraseña para continuar.');
    $this->actingAs($student)->withHeader('X-Livewire', 'true')->get(route('core.summary'))->assertForbidden();
});

it('AC-12: al entrar con el NIP del SII la credencial no va a la banda y la siguiente página es el cambio', function () {
    nipStudentForNotice('123');

    $this->postJson('/login', ['username' => '21110001', 'password' => '123'])
        ->assertOk()
        ->assertJsonPath('redirect', url('/'))
        ->assertJsonPath('band', false)
        ->assertJsonPath('page', null);

    $this->get('/')->assertRedirect(route('user-password.edit'));
});

it('AC-22: el cambio del NIP es primer acceso y muestra el aviso simplificado, el enlace al integral y la casilla', function () {
    $this->actingAs(nipStudentForNotice())
        ->get(route('user-password.edit'))
        ->assertOk()
        ->assertSee('data-login-scene', false)
        ->assertSee('Cambia tu contraseña')
        ->assertSee('Entraste con tu NIP del SII')
        ->assertSee('AVISO DE PRIVACIDAD SIMPLIFICADO')
        ->assertSee('FECHA DE REALIZACIÓN: 03/MARZO/2026.')
        ->assertSee('href="'.route('privacy-notice').'#integral"', false)
        ->assertSee('He leído el aviso de privacidad y acepto el tratamiento de mis datos personales')
        ->assertSee('name="privacy_notice"', false)
        ->assertSee('Cerrar sesión');
});

it('AC-22: sin marcar la casilla no cambia la contraseña ni guarda la aceptación', function () {
    $student = nipStudentForNotice();

    $this->actingAs($student)
        ->from(route('user-password.edit'))
        ->put(route('user-password.update'), newPasswordForNotice())
        ->assertRedirect(route('user-password.edit'))
        ->assertSessionHasErrors(['privacy_notice' => 'Para seguir, marca que leíste el aviso de privacidad y aceptas el tratamiento de tus datos personales.']);

    $student->refresh();

    expect($student->password_origin)->toBe(PasswordOrigin::SiiNip)
        ->and($student->privacy_notice_accepted_at)->toBeNull()
        ->and($student->privacy_notice_version)->toBeNull()
        ->and(activitySince()->where('event', 'privacy-notice-accepted')->exists())->toBeFalse();
});

it('AC-22: con la casilla cambia el NIP por una propia, guarda la aceptación con su versión y lo deja en la bitácora', function () {
    $student = nipStudentForNotice();

    $this->actingAs($student)
        ->from(route('user-password.edit'))
        ->put(route('user-password.update'), newPasswordForNotice(['privacy_notice' => '1']))
        ->assertRedirect(route('user-password.edit'))
        ->assertSessionHas('status', 'password-updated');

    $student->refresh();

    expect($student->password_origin)->toBe(PasswordOrigin::User)
        ->and(Hash::check('Propia-2027', $student->password))->toBeTrue()
        ->and($student->privacy_notice_accepted_at)->not->toBeNull()
        ->and($student->privacy_notice_version)->toBe(PrivacyNotice::VERSION)
        ->and(PrivacyNotice::VERSION)->toBe('2026-03-03');

    $entry = activitySince()->where('event', 'privacy-notice-accepted')->sole();

    expect($entry->subject_id)->toBe($student->id)
        ->and($entry->causer_id)->toBe($student->id)
        ->and($entry->properties['version'])->toBe(PrivacyNotice::VERSION)
        ->and(activitySince()->where('event', 'password-changed')->count())->toBe(1);

    $this->get(route('user-password.edit'))
        ->assertOk()
        ->assertSee('Tu contraseña se actualizó.')
        ->assertSee('Entrar a Nexus');

    $this->get(route('core.summary'))->assertOk();
});

it('AC-22: el personal que cambia su contraseña no ve el aviso ni la casilla', function () {
    $staff = User::factory()->withRole(Role::Docente)->withoutPrivacyNotice()->create(['must_change_password' => true]);

    $this->actingAs($staff)
        ->get(route('user-password.edit'))
        ->assertOk()
        ->assertDontSee('AVISO DE PRIVACIDAD SIMPLIFICADO')
        ->assertDontSee('name="privacy_notice"', false);

    $this->from(route('user-password.edit'))
        ->put(route('user-password.update'), ['current_password' => 'password', 'password' => 'Propia-2027', 'password_confirmation' => 'Propia-2027'])
        ->assertSessionHasNoErrors();

    expect($staff->refresh()->privacy_notice_accepted_at)->toBeNull();
});

it('AC-22: el alumno con contraseña propia que no ha aceptado el aviso va a la pantalla del aviso', function () {
    $this->actingAs(ownPasswordStudentForNotice())
        ->get(route('core.summary'))
        ->assertRedirect(route('privacy-notice.accept'));
});

it('AC-22: sin aceptar el aviso, una petición JSON o de Livewire recibe 403', function () {
    $student = ownPasswordStudentForNotice();

    $this->actingAs($student)->getJson(route('core.summary'))->assertForbidden()->assertJsonPath('message', 'Acepta el aviso de privacidad para continuar.');
    $this->actingAs($student)->withHeader('X-Livewire', 'true')->get(route('core.summary'))->assertForbidden();
});

it('AC-22: sin aceptar el aviso puede leer la página pública, cambiar su contraseña y salir', function () {
    $student = ownPasswordStudentForNotice();

    $this->actingAs($student)->get(route('privacy-notice'))->assertOk();
    $this->actingAs($student)->get(route('user-password.edit'))->assertOk()->assertSee('name="privacy_notice"', false);
    $this->actingAs($student)->post(route('logout'));

    $this->assertGuest();
});

it('AC-22: la pantalla del aviso muestra el simplificado, el enlace al integral, «Acepto» y «Salir»', function () {
    $this->actingAs(ownPasswordStudentForNotice())
        ->get(route('privacy-notice.accept'))
        ->assertOk()
        ->assertSee('data-login-scene', false)
        ->assertSee('Aviso de privacidad')
        ->assertSee('AVISO DE PRIVACIDAD SIMPLIFICADO')
        ->assertSee('href="'.route('privacy-notice').'#integral"', false)
        ->assertSee('action="'.route('privacy-notice.accept.store').'"', false)
        ->assertSee('Acepto')
        ->assertSee('action="'.route('logout').'"', false)
        ->assertSee('Salir');
});

it('AC-22: «Acepto» guarda la aceptación vigente, deja la bitácora y sigue a la página que había pedido', function () {
    $student = ownPasswordStudentForNotice();

    $this->actingAs($student)->get(route('core.summary'))->assertRedirect(route('privacy-notice.accept'));

    $this->post(route('privacy-notice.accept.store'))->assertRedirect(route('core.summary'));

    $student->refresh();

    expect($student->privacy_notice_version)->toBe(PrivacyNotice::VERSION)
        ->and($student->privacy_notice_accepted_at)->not->toBeNull()
        ->and(activitySince()->where('event', 'privacy-notice-accepted')->where('subject_id', $student->id)->count())->toBe(1);

    $this->get(route('core.summary'))->assertOk();
    $this->get(route('privacy-notice.accept'))->assertRedirect(route('home'));
});

it('AC-22: el alumno que aceptó la versión vigente pasa sin detenerse', function () {
    $this->actingAs(User::factory()->withRole(Role::Alumno)->create())
        ->get(route('core.summary'))
        ->assertOk();
});

it('AC-22: una versión nueva del aviso pide aceptarlo otra vez', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['privacy_notice_version' => '2025-01-01', 'privacy_notice_accepted_at' => now()->subYear()]);

    $this->actingAs($student)->get(route('core.summary'))->assertRedirect(route('privacy-notice.accept'));
});

it('AC-22: al personal nunca se le muestra la pantalla del aviso, aunque no lo haya aceptado', function (User $user) {
    $this->actingAs($user)->get(route('core.summary'))->assertOk();
    $this->actingAs($user)->getJson(route('core.summary'))->assertOk();
    $this->actingAs($user)->get(route('privacy-notice.accept'))->assertRedirect(route('home'));
    $this->actingAs($user)->post(route('privacy-notice.accept.store'))->assertRedirect(route('home'));

    expect($user->refresh()->privacy_notice_accepted_at)->toBeNull()
        ->and(activitySince()->where('event', 'privacy-notice-accepted')->exists())->toBeFalse();
})->with([
    'docente' => fn (): User => User::factory()->withRole(Role::Docente)->withoutPrivacyNotice()->create(),
    'admin' => fn (): User => User::factory()->admin()->withoutPrivacyNotice()->create(),
    'alumno que también es docente' => fn (): User => User::factory()->withRole(Role::Alumno)->withRole(Role::Docente)->withoutPrivacyNotice()->create(),
]);
