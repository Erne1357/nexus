<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| «Personas y puestos» con las cuentas (1.22-C1): la acción principal «Dar de alta» (CF-S3, un cajón con usuario, nombre
| y correo institucional) y los filtros «Estado» y «Acceso». Solo el admin; ningún otro puesto ve la acción ni la hace,
| aunque llame los métodos sobre una página que abrió el admin.
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'username' => 'ada']);
    $this->beto = User::factory()->create(['name' => 'Beto Sin Acceso', 'username' => '19110002', 'password' => null]);
    $this->cris = User::factory()->create(['name' => 'Cris Inactivo', 'username' => '19110003', 'is_active' => false]);

    $this->activityBase = (int) Activity::max('id');
});

function peopleAccountsPage(?User $as = null)
{
    return Livewire::actingAs($as ?? test()->admin)->test('core::pages.people');
}

it('CF-S3: «Dar de alta» es la acción principal y abre el cajón con usuario, nombre y correo', function () {
    $dom = domOf($this->actingAs($this->admin)->get(route('core.people'))->assertOk());

    expect(textOf($dom->querySelector('[data-testid="catalog-toolbar"] [data-testid="create-user"]')))->toBe('Dar de alta')
        ->and($dom->querySelector('[data-testid="filter-status"]'))->not->toBeNull()
        ->and($dom->querySelector('[data-testid="filter-access"]'))->not->toBeNull();

    peopleAccountsPage()
        ->call('openCreate')
        ->assertSet('showCreate', true)
        ->assertSeeHtml('data-testid="new-username"')
        ->assertSeeHtml('data-testid="new-email"');
});

it('CF-S3 y CF-07: da de alta sin acceso, con el correo en minúsculas, le manda la invitación y lleva a su ficha', function () {
    Notification::fake();

    $page = peopleAccountsPage()
        ->call('openCreate')
        ->set('newUsername', ' RURR800101AB1 ')
        ->set('newName', ' Rosa Ruiz ')
        ->set('newEmail', 'Rosa.Ruiz@CDJUAREZ.tecnm.mx')
        ->call('createUser')
        ->assertHasNoErrors();

    $rosa = User::where('username', 'RURR800101AB1')->sole();

    $page->assertRedirect(route('core.people.show', $rosa->id));

    expect($rosa->name)->toBe('Rosa Ruiz')
        ->and($rosa->email)->toBe('rosa.ruiz@cdjuarez.tecnm.mx')
        ->and($rosa->is_active)->toBeTrue()
        ->and($rosa->password)->toBeNull()
        ->and(UserAccess::of($rosa))->toBe(UserAccess::Invited)
        ->and($rosa->access_invited_by)->toBe($this->admin->id)
        ->and(lastToastOf($page))->type->toBe('success')->title->toBe('Usuario dado de alta')->description->toBe('Invitación enviada a rosa.ruiz@cdjuarez.tecnm.mx. Nómbralo en su ficha.')
        ->and(activitySince()->where('event', 'created')->sole()->causer_id)->toBe($this->admin->id)
        ->and(activitySince()->where('event', 'access-invited')->sole()->causer_id)->toBe($this->admin->id);
    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'rosa.ruiz@cdjuarez.tecnm.mx'
        && $invitation->username === 'RURR800101AB1');
});

it('CF-S3 y CF-07: el correo es opcional; sin él queda sin acceso y no se manda nada', function () {
    Notification::fake();

    $page = peopleAccountsPage()->call('openCreate')->set('newUsername', '777')->set('newName', 'Sin Correo')->call('createUser')->assertHasNoErrors();
    $user = User::where('username', '777')->sole();

    expect($user->email)->toBeNull()
        ->and(UserAccess::of($user))->toBe(UserAccess::None)
        ->and(lastToastOf($page))->description->toBe('777 queda sin acceso hasta que captures su correo institucional. Nómbralo en su ficha.');
    Notification::assertNothingSent();
});

it('CF-S3: los errores salen junto a su campo y no da de alta', function (array $fields, string $field, string $message) {
    User::factory()->create(['username' => 'TOMADO', 'email' => 'Tomado@cdjuarez.tecnm.mx']);

    $page = peopleAccountsPage()->call('openCreate');

    foreach (['newUsername' => 'NUEVO', 'newName' => 'Nuevo', 'newEmail' => '', ...$fields] as $key => $value) {
        $page->set($key, $value);
    }

    $page->call('createUser')->assertHasErrors([$field])->assertSee($message);

    expect(User::where('username', 'NUEVO')->exists())->toBeFalse();
})->with([
    'sin usuario' => [['newUsername' => '  '], 'newUsername', 'Escribe el usuario.'],
    'sin nombre' => [['newName' => ''], 'newName', 'Escribe el nombre.'],
    'nombre largo' => [['newName' => str_repeat('a', 161)], 'newName', 'El nombre no puede pasar de 160 caracteres.'],
    'usuario repetido' => [['newUsername' => 'TOMADO'], 'newUsername', 'Ya existe un usuario con ese nombre de usuario.'],
    'otro dominio' => [['newEmail' => 'nuevo@gmail.com'], 'newEmail', 'El correo institucional debe terminar en @cdjuarez.tecnm.mx.'],
    'correo repetido' => [['newEmail' => 'TOMADO@cdjuarez.tecnm.mx'], 'newEmail', 'Otro usuario ya tiene el correo tomado@cdjuarez.tecnm.mx.'],
]);

it('filtra por estado de la cuenta y por estado del acceso, y muestra la columna Acceso', function () {
    $this->actingAs($this->admin)->get(route('core.people'))
        ->assertSeeInOrder(['Usuario', 'Persona', 'Puestos vigentes', 'Estado', 'Acceso'])
        ->assertSee('Sin acceso');

    peopleAccountsPage()
        ->set('status', 'inactive')->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Cris Inactivo'])
        ->set('status', 'active')->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Ada Admin', 'Beto Sin Acceso'])
        ->set('status', '')
        ->set('access', 'sin_acceso')->assertViewHas('people', fn ($people): bool => $people->pluck('name')->all() === ['Beto Sin Acceso'])
        ->set('access', 'nada')->assertViewHas('people', fn ($people): bool => $people->count() === 3);
});

describe('otros puestos', function () {
    it('no ven «Dar de alta» ni la hacen, aunque llamen los métodos sobre una página que abrió el admin', function (Role $role, string $method) {
        $other = User::factory()->withRole($role)->create();

        $this->actingAs($other)->get(route('core.people'))->assertForbidden();

        $page = peopleAccountsPage()->set('newUsername', 'COLADO')->set('newName', 'Colado');
        $this->actingAs($other);
        $page->call($method)->assertForbidden();

        expect(User::where('username', 'COLADO')->exists())->toBeFalse()
            ->and(activitySince()->count())->toBe(0);
    })->with([Role::Docente, Role::Alumno])->with(['openCreate', 'createUser']);

    it('el admin con Coordinación activa no da de alta', function () {
        $campus = Campus::factory()->create();
        $career = Career::factory()->create();
        CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id]);
        $both = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $campus->id, 'career_id' => $career->id])->create();

        actingAsRole($both, Role::Coordinador);
        Livewire::test('core::pages.people')->assertForbidden();

        expect(User::where('username', 'COLADO')->exists())->toBeFalse();
    });
});
