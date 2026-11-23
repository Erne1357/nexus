<?php

use App\Modules\Core\Actions\UpdateUserPassword;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PasswordReceiptQuery;
use App\Modules\Core\Support\PasswordRequirements;
use Spatie\Activitylog\Models\Activity;

/*
| Pantallas de contraseña (tarea 1.30; DESIGN.md, "Pantallas de contraseña"): la credencial que se imprime con cada
| requisito y el acuse con el folio de la bitácora al guardar. En el primer acceso cuelga en la escena del login; en un
| cambio voluntario, de la banda de la aplicación.
*/

function changeOwnPassword(User $user, string $current): void
{
    test()->actingAs($user)->from(route('user-password.edit'))->put(route('user-password.update'), [
        'current_password' => $current,
        'password' => 'Nueva-clave-2027',
        'password_confirmation' => 'Nueva-clave-2027',
    ]);
}

it('en el primer acceso la credencial cuelga en la escena del login, provisional y con los cinco requisitos', function () {
    $user = User::factory()->create(['name' => 'Mariana Ortega Ruiz', 'password' => 'Inicial-2026', 'must_change_password' => true]);

    $response = $this->actingAs($user)->get(route('user-password.edit'))->assertOk();

    $response->assertSee('data-password-badge', false)
        ->assertSee('Provisional')
        ->assertSee('Es tu primer acceso: elige una propia.')
        ->assertSee('Mariana Ortega Ruiz')
        ->assertSee('data-login-scene', false)
        ->assertSee('Cerrar sesión')
        ->assertDontSee('data-password-receipt', false)
        ->assertDontSee('livewire:core::components.band', false);

    foreach (PasswordRequirements::forChange() as $label) {
        $response->assertSee($label);
    }
});

it('el cambio voluntario cuelga de la banda de la aplicación, con «Volver sin cambiarla»', function () {
    $user = User::factory()->withRole(Role::Docente)->create(['password' => 'Inicial-2026']);

    $this->actingAs($user)->get(route('user-password.edit'))
        ->assertOk()
        ->assertSee('data-password-badge', false)
        ->assertSee('Al guardarla se cierran tus demás sesiones abiertas.')
        ->assertSee('Volver sin cambiarla')
        ->assertSee('nexus-content', false)
        ->assertDontSee('data-login-scene', false);
});

it('al guardar cuelga el acuse con el folio de la bitácora, la fecha y sin la contraseña', function () {
    $user = User::factory()->withRole(Role::Docente)->create(['password' => 'Inicial-2026']);

    changeOwnPassword($user, 'Inicial-2026');

    $entry = Activity::query()->where('event', 'password-changed')->where('subject_id', $user->id)->sole();

    $this->get(route('user-password.edit'))
        ->assertOk()
        ->assertSee('data-password-receipt', false)
        ->assertSee('Acuse del cambio')
        ->assertSee('Folio '.$entry->id)
        ->assertSee('Quedó en la bitácora a tu nombre.')
        ->assertSee('Ir al inicio')
        ->assertDontSee('Nueva-clave-2027');
});

it('el acuse sale solo justo después de guardar: al volver a la página, otra vez el formulario', function () {
    $user = User::factory()->withRole(Role::Docente)->create(['password' => 'Inicial-2026']);

    changeOwnPassword($user, 'Inicial-2026');
    $this->get(route('user-password.edit'))->assertSee('data-password-receipt', false);

    $this->get(route('user-password.edit'))->assertDontSee('data-password-receipt', false)->assertSee('Guardar contraseña');
});

it('el folio del acuse es el del último cambio de esa persona, nunca el de otra', function () {
    $other = User::factory()->create(['password' => 'Inicial-2026']);
    $user = User::factory()->create(['password' => 'Inicial-2026']);
    $input = ['current_password' => 'Inicial-2026', 'password' => 'Nueva-clave-2027', 'password_confirmation' => 'Nueva-clave-2027'];
    app(UpdateUserPassword::class)->update($user, $input);
    app(UpdateUserPassword::class)->update($other, $input);

    $own = Activity::query()->where('event', 'password-changed')->where('subject_id', $user->id)->sole();

    expect(app(PasswordReceiptQuery::class)->latestFor($user))->toMatchArray(['folio' => $own->id])
        ->and(app(PasswordReceiptQuery::class)->latestFor(User::factory()->create()))->toBeNull();
});

it('los requisitos del enlace del correo no piden que sea distinta de la actual', function () {
    expect(array_keys(PasswordRequirements::forReset()))->toBe(['length', 'letter', 'number', 'match'])
        ->and(array_keys(PasswordRequirements::forChange()))->toBe(['length', 'letter', 'number', 'different', 'match']);
});

it('«Volver sin cambiarla» solo regresa a una página de Nexus: con un Referer de otro sitio lleva al inicio', function (string $referer, string $expected) {
    $user = User::factory()->create(['password' => 'Inicial-2026']);

    $this->actingAs($user)
        ->withHeader('Referer', $referer === 'propia' ? url('/notifications') : $referer)
        ->get(route('user-password.edit'))
        ->assertOk()
        ->assertSee('href="'.($expected === 'propia' ? url('/notifications') : route('home')).'"', false)
        ->assertDontSee('href="'.$referer.'"', false);
})->with([
    'de otro sitio' => ['https://malicioso.example/robar', 'inicio'],
    'que imita el dominio' => ['http://localhost.malicioso.example/x', 'inicio'],
    'de Nexus' => ['propia', 'propia'],
]);
