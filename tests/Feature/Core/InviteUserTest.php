<?php

use App\Modules\Core\Actions\AcceptAccessInvitation;
use App\Modules\Core\Actions\CancelAccessInvitation;
use App\Modules\Core\Actions\DeactivateUser;
use App\Modules\Core\Actions\InviteUser;
use App\Modules\Core\Actions\RevokeUserAccess;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Actions\UpdateUserProfile;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| InviteUser, la invitación del admin (CF-07, confirmado el 14 de noviembre de 2026): a cualquier usuario activo, sin
| contraseña y con correo institucional, tenga o no un puesto (jefes, Subdirección, Servicios Escolares, otro admin…),
| le llegan su usuario y el enlace de AC-07; elige su contraseña al activar (AC-08). La invitación recuerda que la mandó
| el admin (`users.access_invited_by`): por eso su enlace abre aunque tenga un puesto, y el de una invitación
| automática no.
*/

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
    $this->activityBase = (int) Activity::max('id');
});

/**
 * Un usuario sin acceso con correo institucional y, si se pide, un puesto vigente.
 */
function uninvitedStaff(?Role $role = null, string $email = 'rosa.ruiz@cdjuarez.tecnm.mx'): User
{
    $factory = User::factory();

    if ($role !== null) {
        $factory = $factory->withRole($role, $role === Role::JefeDepartamento ? ['department_id' => departmentKey(10)] : []);
    }

    return $factory->create(['username' => 'RURR800101AB1', 'name' => 'Rosa Ruiz', 'email' => $email, 'password' => null, 'must_change_password' => true]);
}

/**
 * El enlace de la última invitación que se mandó a `$email`.
 */
function lastInvitationLinkTo(string $email): string
{
    $link = null;

    Notification::assertSentOnDemand(AccessInvitation::class, function (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable) use (&$link, $email): bool {
        if ($notifiable->routes['mail'] === $email) {
            $link = $invitation->url;
        }

        return true;
    });

    return $link;
}

it('CF-07: el admin invita al personal con o sin puesto: le llegan su usuario y el enlace, y la invitación recuerda que la mandó él', function (?Role $role) {
    $rosa = uninvitedStaff($role);

    expect(app(InviteUser::class)->handle($rosa, $this->admin))->toBe('rosa.ruiz@cdjuarez.tecnm.mx');

    $fresh = $rosa->fresh();

    expect($fresh->access_invited_at)->not->toBeNull()
        ->and($fresh->access_invited_by)->toBe($this->admin->id)
        ->and($fresh->email_verified_at)->toBeNull()
        ->and(UserAccess::of($fresh))->toBe(UserAccess::Invited);

    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'rosa.ruiz@cdjuarez.tecnm.mx'
        && $invitation->username === 'RURR800101AB1');

    $log = activitySince()->where('event', 'access-invited')->sole();

    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->subject_id)->toBe($rosa->id)
        ->and($log->properties->toArray())->toBe(['email' => 'rosa.ruiz@cdjuarez.tecnm.mx', 'by_admin' => true]);
})->with([
    'jefe de departamento' => [Role::JefeDepartamento],
    'Subdirección' => [Role::Subdireccion],
    'otro admin' => [Role::Admin],
    'sin puesto' => [null],
]);

it('CF-07: el enlace del admin abre aunque tenga un puesto distinto de docente, y con él elige su contraseña', function () {
    $rosa = uninvitedStaff(Role::JefeDepartamento);
    app(InviteUser::class)->handle($rosa, $this->admin);
    $link = lastInvitationLinkTo('rosa.ruiz@cdjuarez.tecnm.mx');

    $this->get($link)->assertOk()->assertSee('Activar mi acceso');
    $this->post($link, ['password' => 'Propia-clave-2027', 'password_confirmation' => 'Propia-clave-2027'])->assertRedirect(route('home'));

    expect($rosa->fresh()->password)->not->toBeNull()
        ->and($rosa->fresh()->email_verified_at)->not->toBeNull();
});

it('CF-07: la invitación automática de un docente no abre si después lo nombran jefe; la del admin sí', function () {
    $ana = User::factory()->withRole(Role::Docente)->create(['email' => null, 'password' => null]);
    app(SendAccessInvitation::class)->handle($ana, 'ana.perez@cdjuarez.tecnm.mx', $this->admin);
    $automatic = lastInvitationLinkTo('ana.perez@cdjuarez.tecnm.mx');

    RoleAssignment::factory()->for($ana)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => departmentKey(10), 'valid_from' => today()->toDateString()]);

    expect($ana->fresh()->access_invited_by)->toBeNull();
    $this->get($automatic)->assertForbidden()->assertSee('Este enlace ya no sirve');

    $this->travel(1)->minutes();
    app(InviteUser::class)->handle($ana->fresh(), $this->admin);
    $fromAdmin = lastInvitationLinkTo('ana.perez@cdjuarez.tecnm.mx');

    $this->get($fromAdmin)->assertOk()->assertSee('Activar mi acceso');
});

it('CF-07: una invitación automática después de la del admin vuelve a ser automática: ya no abre con un puesto', function () {
    $ana = User::factory()->withRole(Role::Docente)->create(['email' => 'ana.perez@cdjuarez.tecnm.mx', 'password' => null]);
    app(InviteUser::class)->handle($ana, $this->admin);
    $this->travel(1)->minutes();
    app(SendAccessInvitation::class)->handle($ana, 'ana.perez@cdjuarez.tecnm.mx', $this->admin);
    $link = lastInvitationLinkTo('ana.perez@cdjuarez.tecnm.mx');

    RoleAssignment::factory()->for($ana)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => departmentKey(10), 'valid_from' => today()->toDateString()]);

    expect($ana->fresh()->access_invited_by)->toBeNull();
    $this->get($link)->assertForbidden();
});

it('CF-07: reenviar deja sin efecto el enlace anterior', function () {
    $rosa = uninvitedStaff(Role::Subdireccion);
    app(InviteUser::class)->handle($rosa, $this->admin);
    $first = lastInvitationLinkTo('rosa.ruiz@cdjuarez.tecnm.mx');

    $this->travel(1)->minutes();
    app(InviteUser::class)->handle($rosa->fresh(), $this->admin);

    $this->get($first)->assertForbidden();
    $this->get(lastInvitationLinkTo('rosa.ruiz@cdjuarez.tecnm.mx'))->assertOk();
    Notification::assertSentOnDemandTimes(AccessInvitation::class, 2);
});

it('CF-07: no invita sin correo institucional, a una cuenta inactiva o dada de baja, a quien ya tiene acceso ni a un alumno', function (Closure $spoil, string $message) {
    $rosa = uninvitedStaff(Role::Subdireccion);
    $spoil($rosa, $this->admin);

    expect(app(UserAccountRules::class)->invitationBlocker($rosa->fresh()))->toBe($message)
        ->and(fn () => app(InviteUser::class)->handle($rosa->fresh(), $this->admin))->toThrow(ValidationException::class, $message);

    expect($rosa->fresh()->access_invited_at)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'sin correo' => [fn (User $rosa) => $rosa->forceFill(['email' => null])->save(), UserAccountRules::INVITE_NO_EMAIL],
    'correo de otro dominio' => [fn (User $rosa) => $rosa->forceFill(['email' => 'rosa@itcj.edu.mx'])->save(), UserAccountRules::INVITE_NO_EMAIL],
    'inactiva' => [fn (User $rosa) => $rosa->forceFill(['is_active' => false])->save(), UserAccountRules::INVITE_INACTIVE],
    'dada de baja en el panel (CF-09)' => [fn (User $rosa, User $admin) => app(DeactivateUser::class)->handle($rosa, DeactivationReason::Otro, null, $admin), UserAccountRules::INVITE_INACTIVE],
    'con acceso' => [fn (User $rosa) => $rosa->forceFill(['password' => 'Clave-propia-2026'])->save(), UserAccountRules::INVITE_HAS_ACCESS],
    'alumno (CF-S4)' => [function (User $rosa) {
        $rosa->roleAssignments()->delete();
        RoleAssignment::factory()->for($rosa)->create(['role' => Role::Alumno, 'valid_from' => '2026-08-01']);
    }, UserAccountRules::STUDENT],
]);

it('CF-07: dejar sin efecto la invitación también olvida quién la mandó', function (Closure $cancel) {
    $rosa = uninvitedStaff(Role::Subdireccion);
    app(InviteUser::class)->handle($rosa, $this->admin);

    $cancel($rosa->fresh(), $this->admin);

    expect($rosa->fresh())->access_invited_at->toBeNull()->access_invited_by->toBeNull();
})->with([
    'CancelAccessInvitation' => [fn (User $rosa, User $admin) => app(CancelAccessInvitation::class)->handle($rosa, $admin)],
    'cambiar el correo (UpdateUserProfile)' => [fn (User $rosa, User $admin) => app(UpdateUserProfile::class)->handle($rosa, ['email' => 'rosa.r@cdjuarez.tecnm.mx'], $admin)],
    'quitar el acceso (RevokeUserAccess)' => [fn (User $rosa, User $admin) => app(RevokeUserAccess::class)->handle($rosa, $admin)],
    'dar de baja (DeactivateUser)' => [fn (User $rosa, User $admin) => app(DeactivateUser::class)->handle($rosa, DeactivationReason::Otro, null, $admin)],
]);

it('CF-07: la base no deja a `access_invited_by` sin una invitación', function () {
    $rosa = uninvitedStaff();

    expect(fn () => $rosa->forceFill(['access_invited_by' => $this->admin->id, 'access_invited_at' => null])->save())
        ->toThrow(QueryException::class, 'users_access_invited_by_requires_at');
});

it('CF-07: aceptar la invitación del admin deja la contraseña propia y cierra el enlace', function () {
    $rosa = uninvitedStaff(Role::Admin);
    app(InviteUser::class)->handle($rosa, $this->admin);
    $link = lastInvitationLinkTo('rosa.ruiz@cdjuarez.tecnm.mx');
    $hash = Str::before(Str::after($link, "/access/{$rosa->id}/"), '?');

    expect(app(AcceptAccessInvitation::class)->handle($rosa->id, $hash, ['password' => 'Propia-clave-2027', 'password_confirmation' => 'Propia-clave-2027']))->not->toBeNull()
        ->and(app(AcceptAccessInvitation::class)->handle($rosa->id, $hash, ['password' => 'Otra-clave-2027', 'password_confirmation' => 'Otra-clave-2027']))->toBeNull();
});
