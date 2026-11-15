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
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ReactivateUser, «Reactivar» una cuenta dada de baja (CF-06): vuelve activa y sin acceso, y sus puestos no regresan
| solos. La invitación automática llega con 1.22-C2 (CF-07); mientras, la cuenta queda «Sin acceso». No reactiva a un
| alumno (CF-S4).
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz', 'username' => 'RURR800101AB1', 'email' => 'rosa.ruiz@cdjuarez.tecnm.mx']);

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function reactivateAccount(User $user, ?User $actor = null): void
{
    app(ReactivateUser::class)->handle($user, $actor ?? test()->admin);
}

it('CF-06: reactivar deja la cuenta activa y sin acceso, sin regresarle los puestos que terminó la baja', function () {
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $isc = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $isc->id, 'campus_id' => $c2->id]);
    $coordination = RoleAssignment::factory()->create(['user_id' => $this->rosa->id, 'role' => Role::Coordinador, 'campus_id' => $c2->id, 'career_id' => $isc->id, 'valid_from' => '2026-08-01']);

    app(DeactivateUser::class)->handle($this->rosa, DeactivationReason::CambioDeArea, null, $this->admin);
    $this->activityBase = (int) Activity::max('id');

    reactivateAccount($this->rosa);

    $rosa = $this->rosa->fresh();

    expect($rosa->is_active)->toBeTrue()
        ->and($rosa->password)->toBeNull()
        ->and(UserAccess::of($rosa))->toBe(UserAccess::None)
        ->and($rosa->deactivated_at)->toBeNull()
        ->and($rosa->deactivated_by)->toBeNull()
        ->and($coordination->fresh()->valid_to->toDateString())->toBe('2026-11-14')
        ->and(RoleAssignment::where('user_id', $rosa->id)->count())->toBe(1);

    $entry = activitySince()->sole();

    expect($entry->event)->toBe('reactivated')
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => true, 'deactivated_at' => null], 'old' => ['is_active' => false, 'deactivated_at' => '2026-11-14T10:00:00-07:00']])
        ->and($entry->description)->toBe('Reactivó la cuenta de RURR800101AB1, sin acceso');
});

it('CF-06: una cuenta inactiva que conservaba su contraseña vuelve sin ella', function () {
    $this->rosa->forceFill(['is_active' => false, 'password' => 'Clave-vieja-2026'])->save();

    reactivateAccount($this->rosa);

    expect($this->rosa->fresh()->password)->toBeNull()
        ->and($this->rosa->fresh()->password_origin)->toBeNull()
        ->and(activitySince()->sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['is_active' => true, 'access' => 'sin_acceso'],
            'old' => ['is_active' => false, 'access' => 'con_acceso'],
        ]);
});

it('CF-06: avisa con UserReactivated después del commit', function () {
    Event::fake([UserReactivated::class]);
    $this->rosa->forceFill(['is_active' => false])->save();

    reactivateAccount($this->rosa);

    Event::assertDispatched(UserReactivated::class, fn (UserReactivated $event): bool => $event->user->is($this->rosa));
});

it('CF-06: una cuenta activa no se reactiva', function () {
    expect(fn () => reactivateAccount($this->rosa))->toThrow(ValidationException::class, UserAccountRules::ALREADY_ACTIVE);

    expect(activitySince()->count())->toBe(0);
});

it('CF-S4: no reactiva a un alumno: su acceso sigue su estatus', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['is_active' => false]);

    expect(fn () => reactivateAccount($student))->toThrow(ValidationException::class, UserAccountRules::STUDENT);

    expect($student->fresh()->is_active)->toBeFalse()
        ->and(activitySince()->count())->toBe(0);
});
