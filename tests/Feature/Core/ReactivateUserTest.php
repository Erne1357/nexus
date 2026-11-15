<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Actions\DeactivateUser;
use App\Modules\Core\Actions\ReactivateUser;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Events\UserReactivated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\AccessInvitation;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ReactivateUser, «Reactivar» una cuenta dada de baja (CF-06): vuelve activa y sin acceso, y sus puestos no regresan
| solos. Con correo institucional le llega una invitación nueva (CF-07); sin él queda «Sin acceso». No reactiva a un
| alumno (CF-S4).
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');
    Notification::fake();

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz', 'username' => 'RURR800101AB1', 'email' => 'rosa.ruiz@cdjuarez.tecnm.mx']);

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function reactivateAccount(User $user, ?User $actor = null): ?string
{
    return app(ReactivateUser::class)->handle($user, $actor ?? test()->admin);
}

/**
 * Rosa con un nombramiento de coordinadora desde agosto, y otro que empieza hoy, dada de baja hoy.
 *
 * @return array{0: RoleAssignment, 1: RoleAssignment}
 */
function deactivatedToday(User $user, User $admin): array
{
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $isc = Career::factory()->create(['code' => 'ISC']);
    $ige = Career::factory()->create(['code' => 'IGE']);
    CareerCampus::factory()->create(['career_id' => $isc->id, 'campus_id' => $c2->id]);
    CareerCampus::factory()->create(['career_id' => $ige->id, 'campus_id' => $c2->id]);
    $since = RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Coordinador, 'campus_id' => $c2->id, 'career_id' => $isc->id, 'valid_from' => '2026-08-01']);
    $fromToday = RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Coordinador, 'campus_id' => $c2->id, 'career_id' => $ige->id, 'valid_from' => '2026-11-14']);

    app(DeactivateUser::class)->handle($user, DeactivationReason::CambioDeArea, null, $admin);

    return [$since, $fromToday];
}

it('CF-06: reactivar deja la cuenta activa y sin acceso, sin regresarle los puestos que terminó la baja', function () {
    $this->travelTo('2026-11-14 08:00:00');
    $this->rosa->forceFill(['email' => null])->save();
    [$since] = deactivatedToday($this->rosa, $this->admin);
    $this->travelTo('2026-11-16 09:00:00');
    $this->activityBase = (int) Activity::max('id');

    expect(reactivateAccount($this->rosa))->toBeNull();

    $rosa = $this->rosa->fresh();

    expect($rosa->is_active)->toBeTrue()
        ->and($rosa->password)->toBeNull()
        ->and(UserAccess::of($rosa))->toBe(UserAccess::None)
        ->and($rosa->deactivated_at)->toBeNull()
        ->and($rosa->deactivated_by)->toBeNull()
        ->and($since->fresh()->valid_to->toDateString())->toBe('2026-11-14')
        ->and($rosa->roleAssignments()->current()->count())->toBe(0);

    $entry = activitySince()->sole();

    expect($entry->event)->toBe('reactivated')
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => true, 'deactivated_at' => null], 'old' => ['is_active' => false, 'deactivated_at' => '2026-11-14T08:00:00-07:00']])
        ->and($entry->description)->toBe('Reactivó la cuenta de RURR800101AB1, sin acceso');
    Notification::assertNothingSent();
});

it('CF-06 y CF-07: con correo institucional, después de reactivarla le llega una invitación nueva del admin', function () {
    app(DeactivateUser::class)->handle($this->rosa, DeactivationReason::Otro, null, $this->admin);
    $this->activityBase = (int) Activity::max('id');

    expect(reactivateAccount($this->rosa))->toBe('rosa.ruiz@cdjuarez.tecnm.mx');

    $rosa = $this->rosa->fresh();

    expect(UserAccess::of($rosa))->toBe(UserAccess::Invited)
        ->and($rosa->access_invited_by)->toBe($this->admin->id)
        ->and(activitySince()->orderBy('id')->pluck('event')->all())->toBe(['reactivated', 'access-invited']);
    Notification::assertSentOnDemand(AccessInvitation::class, fn (AccessInvitation $invitation, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'rosa.ruiz@cdjuarez.tecnm.mx');
});

it('CF-06 y CF-07: con un correo que no es institucional se reactiva sin invitación', function () {
    $this->rosa->forceFill(['email' => 'rosa@itcj.edu.mx'])->save();
    app(DeactivateUser::class)->handle($this->rosa, DeactivationReason::Otro, null, $this->admin);

    expect(reactivateAccount($this->rosa))->toBeNull()
        ->and($this->rosa->fresh()->is_active)->toBeTrue()
        ->and(UserAccess::of($this->rosa->fresh()))->toBe(UserAccess::None);
    Notification::assertNothingSent();
});

it('CF-06: reactivar el mismo día de la baja no le devuelve los puestos hasta la medianoche: terminan ayer o se quitan', function () {
    [$since, $fromToday] = deactivatedToday($this->rosa, $this->admin);

    expect($this->rosa->roleAssignments()->current()->count())->toBe(2);

    reactivateAccount($this->rosa);

    expect($this->rosa->roleAssignments()->current()->count())->toBe(0)
        ->and($since->fresh()->valid_to->toDateString())->toBe('2026-11-13')
        ->and(RoleAssignment::find($fromToday->id))->toBeNull()
        ->and($this->rosa->fresh()->is_active)->toBeTrue();
});

it('CF-06: la reactivación del mismo día no toca el nombramiento de docente', function () {
    $teaching = RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Docente, 'valid_from' => '2026-08-01', 'valid_to' => '2026-11-14']);
    app(DeactivateUser::class)->handle($this->rosa, DeactivationReason::Otro, null, $this->admin);

    reactivateAccount($this->rosa);

    expect($teaching->fresh()->valid_to->toDateString())->toBe('2026-11-14');
});

it('CF-06: si algo le dejó contraseña o un enlace mientras estaba dada de baja, vuelve sin ellos', function () {
    app(DeactivateUser::class)->handle($this->rosa, DeactivationReason::Otro, null, $this->admin);
    $this->rosa->forceFill(['password' => 'Clave-vieja-2026', 'password_origin' => 'user', 'email_verified_at' => now()])->save();
    DB::table('password_reset_tokens')->insert(['email' => 'rosa.ruiz@cdjuarez.tecnm.mx', 'token' => 'x', 'created_at' => now()]);
    $this->activityBase = (int) Activity::max('id');

    reactivateAccount($this->rosa);

    expect($this->rosa->fresh()->password)->toBeNull()
        ->and($this->rosa->fresh()->password_origin)->toBeNull()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(activitySince()->where('event', 'reactivated')->sole()->attribute_changes['old'])->toMatchArray(['is_active' => false, 'access' => 'con_acceso']);
});

it('CF-06: avisa con UserReactivated después del commit', function () {
    Event::fake([UserReactivated::class]);
    app(DeactivateUser::class)->handle($this->rosa, DeactivationReason::Otro, null, $this->admin);

    reactivateAccount($this->rosa);

    Event::assertDispatched(UserReactivated::class, fn (UserReactivated $event): bool => $event->user->is($this->rosa));
});

it('CF-06: una cuenta activa no se reactiva', function () {
    expect(fn () => reactivateAccount($this->rosa))->toThrow(ValidationException::class, UserAccountRules::ALREADY_ACTIVE);

    expect(activitySince()->count())->toBe(0);
});

it('CF-06: solo se reactiva la que dio de baja el panel; la de un docente inactivo se reactiva desde Docentes', function () {
    $this->rosa->forceFill(['is_active' => false])->save();
    $teacher = User::factory()->withRole(Role::Docente)->create(['is_active' => false]);

    expect(fn () => reactivateAccount($this->rosa))->toThrow(ValidationException::class, UserAccountRules::NOT_FROM_PANEL)
        ->and(fn () => reactivateAccount($teacher))->toThrow(ValidationException::class, UserAccountRules::FROM_TEACHERS);

    expect($this->rosa->fresh()->is_active)->toBeFalse()
        ->and($teacher->fresh()->is_active)->toBeFalse()
        ->and(activitySince()->count())->toBe(0);
});

it('CF-S4: no reactiva a un alumno: su acceso sigue su estatus', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['is_active' => false]);

    expect(fn () => reactivateAccount($student))->toThrow(ValidationException::class, UserAccountRules::STUDENT);

    expect($student->fresh()->is_active)->toBeFalse()
        ->and(activitySince()->count())->toBe(0);
});

it('CF-S4 y CF-06: quien también era alumno y perdió con la baja su único puesto del personal (aún no empezaba) se reactiva y se edita', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['username' => '19110001']);
    RoleAssignment::factory()->create(['user_id' => $student->id, 'role' => Role::Admin, 'valid_from' => '2026-12-01']);

    app(DeactivateUser::class)->handle($student, DeactivationReason::Otro, null, $this->admin);

    expect(RoleAssignment::where('user_id', $student->id)->pluck('role')->all())->toBe([Role::Alumno])
        ->and(app(UserAccountRules::class)->updateBlocker($student->fresh()))->toBeNull();

    reactivateAccount($student);

    expect($student->fresh()->is_active)->toBeTrue()
        ->and(app(UserAccountRules::class)->isStudent($student->fresh()))->toBeTrue();
});
