<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\PasswordResetLink;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| La cuenta en la ficha de la persona (1.22-C1): el bloque «Cuenta» (usuario, correo, estado, acceso y último acceso) y
| el menú «Acciones» con Editar datos (modal), Restablecer contraseña (CF-08), Dar de baja (cajón con la vista previa,
| CF-05 y CF-09) y Reactivar (CF-06), según el estado. Lo que no se puede queda deshabilitado con su motivo; la cuenta
| de un alumno es de solo lectura salvo Restablecer (CF-S4). Solo el admin.
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');
    Notification::fake();

    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isc = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'username' => 'ada']);
    $this->rosa = User::factory()->create([
        'name' => 'Rosa Ruiz',
        'username' => 'RURR800101AB1',
        'email' => 'rosa.ruiz@cdjuarez.tecnm.mx',
        'password' => 'Clave-propia-2026',
        'last_login_at' => '2026-11-10 08:30:00',
    ]);
    $this->coordination = RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => $this->isc->id, 'valid_from' => '2026-08-01']);

    $this->activityBase = (int) Activity::max('id');
});

function accountPage(User $person, ?User $as = null)
{
    return Livewire::actingAs($as ?? test()->admin)->test('core::pages.person', ['user' => $person->id]);
}

it('muestra el bloque «Cuenta» y, con la cuenta activa, Editar datos, Restablecer contraseña y Dar de baja', function () {
    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id))->assertOk());

    expect(textOf($dom->querySelector('[data-testid="account-username"]')))->toBe('RURR800101AB1')
        ->and(textOf($dom->querySelector('[data-testid="account-email"]')))->toBe('rosa.ruiz@cdjuarez.tecnm.mx')
        ->and(textOf($dom->querySelector('[data-testid="account-status"]')))->toBe('Activa')
        ->and(textOf($dom->querySelector('[data-testid="account-access"]')))->toBe('Con acceso')
        ->and(textOf($dom->querySelector('[data-testid="account-last-login"]')))->toBe('10/11/2026 08:30')
        ->and($dom->querySelector('[data-testid="account-edit"]'))->not->toBeNull()
        ->and($dom->querySelector('[data-testid="account-reset"]')?->getAttribute('data-confirm-title'))->toBe('¿Mandar el enlace a RURR800101AB1?')
        ->and($dom->querySelector('[data-testid="account-deactivate"]'))->not->toBeNull()
        ->and($dom->querySelector('[data-testid="account-reactivate"]'))->toBeNull();
});

it('Editar datos: el modal trae nombre y correo, guarda con UpdateUserProfile y los errores salen junto al campo', function () {
    $page = accountPage($this->rosa)
        ->call('openProfile')
        ->assertSet('showProfile', true)
        ->assertSet('profileName', 'Rosa Ruiz')
        ->assertSet('profileEmail', 'rosa.ruiz@cdjuarez.tecnm.mx');

    $page->set('profileEmail', 'rosa@gmail.com')->call('saveProfile')
        ->assertHasErrors(['profileEmail'])
        ->assertSee('El correo institucional debe terminar en @cdjuarez.tecnm.mx.');

    $page->set('profileName', 'Rosa Ruiz Almanza')->set('profileEmail', 'Rosa.Almanza@cdjuarez.tecnm.mx')->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('showProfile', false);

    expect($this->rosa->fresh()->only(['name', 'email']))->toBe(['name' => 'Rosa Ruiz Almanza', 'email' => 'rosa.almanza@cdjuarez.tecnm.mx'])
        ->and(lastToastOf($page))->title->toBe('Datos guardados');
});

it('CF-08: Restablecer contraseña manda el enlace y dice a qué correo', function () {
    $page = accountPage($this->rosa)->call('sendPasswordReset');

    Notification::assertSentTo($this->rosa, PasswordResetLink::class);
    expect(lastToastOf($page))->type->toBe('success')->description->toBe('Enlace enviado a rosa.ruiz@cdjuarez.tecnm.mx. Vale 60 minutos.');

    $page->call('sendPasswordReset');

    expect(lastToastOf($page))->type->toBe('error')->description->toBe(UserAccountRules::RESET_RECENT);
});

it('CF-08: sin correo institucional, Restablecer sale deshabilitado con su motivo y la llamada no manda nada', function () {
    $this->rosa->forceFill(['email' => null])->save();

    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id)));
    $item = collect($dom->querySelectorAll('[role="menuitem"]'))->first(fn ($node): bool => str_starts_with(textOf($node), 'Restablecer contraseña'));

    expect($item->getAttribute('aria-disabled'))->toBe('true')
        ->and(textOf($item))->toContain(UserAccountRules::RESET_NO_EMAIL)
        ->and(textOf($dom->querySelector('[data-testid="account-email"]')))->toBe('Sin correo institucional');

    $page = accountPage($this->rosa)->call('sendPasswordReset');

    Notification::assertNothingSent();
    expect(lastToastOf($page))->type->toBe('error')->description->toBe(UserAccountRules::RESET_NO_EMAIL);
});

it('CF-05 y CF-09: el cajón «Dar de baja» muestra la vista previa y da de baja con el motivo', function () {
    RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Docente, 'valid_from' => '2026-08-01']);

    $page = accountPage($this->rosa)->call('openDeactivate')->assertSet('showDeactivate', true);
    $dom = domOf($page);

    expect(textOf($dom->querySelector('[data-testid="deactivate-assignments"]')))->toContain('Coordinador de carrera · ISC · Presencial · Campus II')
        ->and(textOf($dom->querySelector('[data-testid="deactivate-teacher"]')))->toContain('Sigue en el catálogo de docentes.');

    $page->call('deactivate')->assertHasErrors(['deactivationReason' => 'required']);

    expect($this->rosa->fresh()->is_active)->toBeTrue();

    $page->set('deactivationReason', 'fin_de_nombramiento')->set('deactivationNote', 'Terminó su periodo')->call('deactivate')
        ->assertHasNoErrors()
        ->assertSet('showDeactivate', false);

    expect($this->rosa->fresh()->is_active)->toBeFalse()
        ->and($this->coordination->fresh()->valid_to->toDateString())->toBe('2026-11-14')
        ->and(lastToastOf($page))->title->toBe('Cuenta dada de baja')->description->toBe('Terminó 1 nombramiento.')
        ->and(activitySince()->where('event', 'deactivated')->sole()->properties->get('reason'))->toBe('fin_de_nombramiento');
});

it('CF-05: el admin no se da de baja a sí mismo: sale deshabilitado con su motivo y la llamada lo rechaza', function () {
    User::factory()->admin()->create();

    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->admin->id)));
    $item = collect($dom->querySelectorAll('[role="menuitem"]'))->first(fn ($node): bool => str_starts_with(textOf($node), 'Dar de baja'));

    expect($item->getAttribute('aria-disabled'))->toBe('true')
        ->and(textOf($item))->toContain(UserAccountRules::SELF);

    accountPage($this->admin)->call('openDeactivate')->set('deactivationReason', 'otro')->call('deactivate')
        ->assertHasErrors(['user'])
        ->assertSee(UserAccountRules::SELF);

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('CF-06: una cuenta dada de baja ofrece Reactivar y Editar datos; al reactivar queda sin acceso', function () {
    $this->rosa->forceFill(['is_active' => false])->save();

    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id)));

    expect($dom->querySelector('[data-testid="account-reactivate"]')?->getAttribute('data-confirm-title'))->toBe('¿Reactivar la cuenta de RURR800101AB1?')
        ->and($dom->querySelector('[data-testid="account-edit"]'))->not->toBeNull()
        ->and($dom->querySelector('[data-testid="account-deactivate"]'))->toBeNull()
        ->and($dom->querySelector('[data-testid="account-reset"]'))->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="account-status"]')))->toBe('Dada de baja');

    $page = accountPage($this->rosa)->call('reactivate');

    expect($this->rosa->fresh()->is_active)->toBeTrue()
        ->and($this->rosa->fresh()->password)->toBeNull()
        ->and(lastToastOf($page))->title->toBe('Cuenta reactivada');
});

it('CF-S4: la cuenta de un alumno es de solo lectura, salvo Restablecer contraseña', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['email' => 'l19110001@cdjuarez.tecnm.mx', 'email_verified_at' => null]);

    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $student->id)));
    $items = collect($dom->querySelectorAll('[role="menuitem"]'))->mapWithKeys(fn ($node): array => [trim(explode(UserAccountRules::STUDENT, textOf($node))[0]) => $node->getAttribute('aria-disabled')]);

    expect(textOf($dom->querySelector('[data-testid="account-student"]')))->toBe(UserAccountRules::STUDENT)
        ->and($items->map(fn (?string $disabled): bool => $disabled === 'true')->all())->toBe([
            'Editar datos' => true,
            'Restablecer contraseña' => false,
            'Dar de baja' => true,
        ]);

    $page = accountPage($student);
    $page->call('openProfile')->set('profileName', 'Otro')->call('saveProfile')->assertHasErrors(['profileName']);
    $page->call('sendPasswordReset');

    expect($student->fresh()->name)->not->toBe('Otro');
    Notification::assertSentTo($student, PasswordResetLink::class);
});

describe('otros puestos', function () {
    it('no ven el bloque ni hacen nada de la cuenta, aunque llamen los métodos sobre una ficha que abrió el admin', function (string $method) {
        $other = User::factory()->withRole(Role::Docente)->create();

        $this->actingAs($other)->get(route('core.people.show', $this->rosa->id))->assertForbidden();

        $page = accountPage($this->rosa)->set('deactivationReason', 'renuncia')->set('profileName', 'Colado');
        $this->actingAs($other);
        $page->call($method)->assertForbidden();

        expect($this->rosa->fresh()->is_active)->toBeTrue()
            ->and($this->rosa->fresh()->name)->toBe('Rosa Ruiz')
            ->and(activitySince()->count())->toBe(0);
        Notification::assertNothingSent();
    })->with(['openProfile', 'saveProfile', 'sendPasswordReset', 'openDeactivate', 'deactivate', 'reactivate']);

    it('el admin con Coordinación activa no entra a la ficha', function () {
        $both = User::factory()->admin()->withRole(Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id])->create();

        actingAsRole($both, Role::Coordinador)->get(route('core.people.show', $this->rosa->id))->assertForbidden();
    });
});
