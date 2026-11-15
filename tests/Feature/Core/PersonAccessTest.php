<?php

use App\Modules\Core\Actions\DeactivateUser;
use App\Modules\Core\Actions\InviteUser;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| AC-04 y CF-07 en la ficha de la persona de «Personas y puestos»: el estado del acceso y, para quien no tiene
| contraseña, «Enviar invitación» o «Reenviar invitación» en «Acciones», tenga o no un puesto (aquí, la Subdirección).
| Reemplaza a «Dar acceso» con la contraseña por defecto (AC-03): el panel ya no la usa. Solo el admin.
*/

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
    $this->rosa = User::factory()->withRole(Role::Subdireccion)->create(['username' => 'rosa', 'email' => 'rosa.ruiz@cdjuarez.tecnm.mx', 'password' => null, 'must_change_password' => true]);
});

function personInvitePage(User $person, ?User $as = null)
{
    return Livewire::actingAs($as ?? test()->admin)->test('core::pages.person', ['user' => $person->id]);
}

it('AC-04 y CF-07: sin contraseña, la ficha dice «Sin acceso» y «Enviar invitación» le manda usuario y enlace a su correo', function () {
    $response = $this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id))->assertOk()->assertDontSee('Dar acceso');
    $dom = domOf($response);
    $item = $dom->querySelector('[data-testid="account-invite"]');

    expect(textOf($dom->querySelector('[data-testid="user-access"]')))->toBe('Sin acceso')
        ->and($dom->querySelector('[data-testid="grant-access"]'))->toBeNull()
        ->and(textOf($item))->toBe('Enviar invitación')
        ->and($item->getAttribute('data-confirm-title'))->toBe('¿Enviar invitación a rosa?')
        ->and($item->getAttribute('x-confirm'))->toBe('Le llegan a rosa.ruiz@cdjuarez.tecnm.mx su usuario y un enlace para elegir su contraseña. Vale 7 días.');

    $page = personInvitePage($this->rosa)->call('invite');

    expect(lastToastOf($page))->type->toBe('success')->title->toBe('Invitación enviada')->description->toBe('Invitación enviada a rosa.ruiz@cdjuarez.tecnm.mx.')
        ->and($this->rosa->fresh()->access_invited_by)->toBe($this->admin->id)
        ->and($this->rosa->fresh()->password)->toBeNull();
    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'rosa.ruiz@cdjuarez.tecnm.mx');
});

it('CF-07: ya invitada, es «Reenviar invitación» y avisa que el enlace anterior deja de servir', function () {
    app(InviteUser::class)->handle($this->rosa, $this->admin);
    $this->travel(1)->minutes();

    $item = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id)))->querySelector('[data-testid="account-invite"]');

    expect(textOf($item))->toBe('Reenviar invitación')
        ->and($item->getAttribute('x-confirm'))->toEndWith('El enlace anterior deja de servir.');

    $page = personInvitePage($this->rosa)->call('invite');

    expect(lastToastOf($page))->title->toBe('Invitación reenviada')->description->toBe('Invitación enviada a rosa.ruiz@cdjuarez.tecnm.mx. El enlace anterior ya no sirve.');
    Notification::assertSentOnDemandTimes(AccessInvitation::class, 2);
});

it('CF-07: sin correo institucional sale deshabilitada con su motivo y la llamada no manda nada', function (?string $email) {
    $this->rosa->forceFill(['email' => $email])->save();

    $item = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id)))->querySelector('[data-testid="account-invite"]');

    expect(textOf($item))->toBe('Enviar invitación '.UserAccountRules::INVITE_NO_EMAIL)
        ->and($item->getAttribute('aria-disabled'))->toBe('true');

    $page = personInvitePage($this->rosa)->call('invite');

    expect(lastToastOf($page))->type->toBe('error')->title->toBe('No se mandó la invitación')->description->toBe(UserAccountRules::INVITE_NO_EMAIL);
    Notification::assertNothingSent();
})->with([null, 'rosa@itcj.edu.mx']);

it('CF-07: con contraseña no se ofrece la invitación, sino «Restablecer contraseña»; la llamada no la cambia', function () {
    $this->rosa->forceFill(['password' => 'Clave-propia-2026', 'must_change_password' => false])->save();
    $before = $this->rosa->fresh()->password;

    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id)));

    expect($dom->querySelector('[data-testid="account-invite"]'))->toBeNull()
        ->and($dom->querySelector('[data-testid="account-reset"]'))->not->toBeNull()
        ->and(textOf($dom->querySelector('[data-testid="user-access"]')))->toBe('Con acceso');

    $page = personInvitePage($this->rosa)->call('invite');

    expect(lastToastOf($page))->type->toBe('error')->description->toBe(UserAccountRules::INVITE_HAS_ACCESS)
        ->and($this->rosa->fresh()->password)->toBe($before);
    Notification::assertNothingSent();
});

it('CF-07: a una cuenta dada de baja no se le ofrece ni se le manda: primero se reactiva', function () {
    app(DeactivateUser::class)->handle($this->rosa, DeactivationReason::Renuncia, null, $this->admin);

    $dom = domOf($this->actingAs($this->admin)->get(route('core.people.show', $this->rosa->id)));

    expect($dom->querySelector('[data-testid="account-invite"]'))->toBeNull()
        ->and($dom->querySelector('[data-testid="account-reactivate"]'))->not->toBeNull();

    $page = personInvitePage($this->rosa)->call('invite');

    expect(lastToastOf($page))->type->toBe('error')->description->toBe(UserAccountRules::INVITE_INACTIVE);
    Notification::assertNothingSent();
});

it('CF-07: quien no es admin no entra a la ficha ni invita, aunque llame la acción sobre una ficha que abrió el admin', function (Role $role) {
    $other = User::factory()->withRole($role, $role === Role::JefeDepartamento ? ['department_id' => departmentKey(10)] : [])->create();

    $this->actingAs($other)->get(route('core.people.show', $this->rosa->id))->assertForbidden();

    $page = personInvitePage($this->rosa);
    $this->actingAs($other);
    $page->call('invite')->assertForbidden();

    expect($this->rosa->fresh()->access_invited_at)->toBeNull();
    Notification::assertNothingSent();
})->with([Role::Docente, Role::JefeDepartamento]);
