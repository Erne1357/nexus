<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\DeactivateUser;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\UserDeactivated;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| DeactivateUser, «Dar de baja» una cuenta del personal (CF-05, CF-09): termina hoy sus nombramientos vigentes y quita
| los que aún no empiezan, con las reglas de EndRoleAssignment y sin dejar ningún día sin admin (AZ-S12); deja la cuenta
| inactiva, sin contraseña, sin invitación ni enlace pendiente, y la bitácora guarda el motivo. No toca el nombramiento
| de docente (lo maneja Faculty) ni el de alumno, y no da de baja a un alumno (CF-S4) ni a uno mismo.
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');

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
    ]);

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function accountAssignment(User $user, Role $role, array $attributes = []): RoleAssignment
{
    return RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => $role, 'valid_from' => '2026-08-01', ...$attributes]);
}

/**
 * @return array{ended: int, removed: int}
 */
function deactivateAccount(User $user, ?User $actor = null, DeactivationReason $reason = DeactivationReason::Renuncia, ?string $note = null): array
{
    return app(DeactivateUser::class)->handle($user, $reason, $note, $actor ?? test()->admin);
}

it('CF-05: la baja termina hoy los nombramientos vigentes, quita los que aún no empiezan y deja la cuenta inactiva y sin contraseña', function () {
    $coordination = accountAssignment($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);
    $headship = accountAssignment($this->rosa, Role::JefeDepartamento, ['org_unit_id' => $this->systems->id, 'valid_from' => '2026-12-01']);

    $result = deactivateAccount($this->rosa, note: 'Se fue a otra institución');

    $rosa = $this->rosa->fresh();

    expect($result)->toBe(['ended' => 1, 'removed' => 1])
        ->and($coordination->fresh()->valid_to->toDateString())->toBe('2026-11-14')
        ->and(RoleAssignment::find($headship->id))->toBeNull()
        ->and($rosa->is_active)->toBeFalse()
        ->and($rosa->password)->toBeNull()
        ->and($rosa->password_origin)->toBeNull()
        ->and($rosa->deactivated_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($rosa->deactivated_by)->toBe($this->admin->id);
});

it('CF-09: la base no deja activa una cuenta que el admin dio de baja, venga de donde venga la escritura', function () {
    deactivateAccount($this->rosa);

    expect(fn () => DB::table('users')->where('id', $this->rosa->id)->update(['is_active' => true]))
        ->toThrow(QueryException::class, 'users_deactivated_inactive');
});

it('CF-05: la bitácora guarda el motivo, la nota, quién la dio y los nombramientos terminados, sin la contraseña', function () {
    $coordination = accountAssignment($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);

    deactivateAccount($this->rosa, reason: DeactivationReason::Jubilacion, note: 'Se jubiló en noviembre');

    $entry = activitySince()->where('event', 'deactivated')->sole();

    expect($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->subject_id)->toBe($this->rosa->id)
        ->and($entry->properties->get('reason'))->toBe('jubilacion')
        ->and($entry->properties->get('note'))->toBe('Se jubiló en noviembre')
        ->and($entry->properties->get('ended_assignment_ids'))->toBe([$coordination->id])
        ->and($entry->properties->get('removed_assignment_ids'))->toBe([])
        ->and($entry->attribute_changes->toArray())->toBe(['attributes' => ['is_active' => false, 'access' => 'sin_acceso'], 'old' => ['is_active' => true, 'access' => 'con_acceso']])
        ->and($entry->description)->toBe('Dio de baja la cuenta de RURR800101AB1 (Jubilación) y terminó 1 nombramiento')
        ->and(json_encode($entry->toArray()))->not->toContain('Clave-propia-2026')
        ->and(activitySince()->where('properties->role_assignment_id', $coordination->id)->count())->toBe(1);
});

it('CF-05: deja sin efecto el enlace para restablecer y la invitación pendiente', function () {
    $tokens = app(PasswordResetTokens::class);
    $token = $tokens->create($this->rosa);

    $invited = User::factory()->create(['email' => 'beto@cdjuarez.tecnm.mx', 'password' => null, 'email_verified_at' => null]);
    $invited->forceFill(['access_invited_at' => now()->startOfSecond()])->save();
    $link = app(AccessInvitationLink::class);
    $hash = basename((string) parse_url($link->url($invited->fresh()), PHP_URL_PATH));

    expect($link->opens($invited->fresh(), $hash))->toBeTrue();

    deactivateAccount($this->rosa);
    deactivateAccount($invited);

    expect(DB::table('password_reset_tokens')->where('email', 'rosa.ruiz@cdjuarez.tecnm.mx')->exists())->toBeFalse()
        ->and($tokens->opens($this->rosa->fresh(), $token))->toBeFalse()
        ->and($invited->fresh()->access_invited_at)->toBeNull()
        ->and($link->opens($invited->fresh(), $hash))->toBeFalse();
});

it('CF-05: avisa con UserDeactivated después del commit, y no si la transacción se revierte', function () {
    Event::fake([UserDeactivated::class]);

    DB::beginTransaction();
    deactivateAccount($this->rosa);
    DB::rollBack();

    Event::assertNotDispatched(UserDeactivated::class);
    expect($this->rosa->fresh()->is_active)->toBeTrue();

    deactivateAccount($this->rosa, reason: DeactivationReason::Otro);

    Event::assertDispatched(UserDeactivated::class, fn (UserDeactivated $event): bool => $event->user->is($this->rosa) && $event->reason === DeactivationReason::Otro);
});

it('CF-05 y AZ-S12: no da de baja al último admin vigente y no escribe nada', function () {
    $actor = User::factory()->create();

    expect(fn () => deactivateAccount($this->admin, $actor))
        ->toThrow(ValidationException::class, 'Es el último admin vigente hoy: nombra a otra persona antes de darlo de baja.');

    expect($this->admin->fresh()->is_active)->toBeTrue()
        ->and($this->admin->roleAssignments()->sole()->valid_to)->toBeNull()
        ->and(activitySince()->count())->toBe(0);
});

it('CF-05 y AZ-S12: tampoco si otro admin solo cubre hasta una fecha y quedaría un día sin admin', function () {
    $beto = User::factory()->create(['name' => 'Beto Bravo']);
    accountAssignment($beto, Role::Admin, ['valid_from' => '2026-01-01', 'valid_to' => '2026-11-30']);

    expect(fn () => deactivateAccount($this->admin, $beto))
        ->toThrow(ValidationException::class, 'El 01/12/2026 nadie tendría un nombramiento de Administración vigente: nombra a otra persona antes de darlo de baja.');

    accountAssignment($beto, Role::Admin, ['valid_from' => '2026-12-01']);

    deactivateAccount($this->admin, $beto);

    expect($this->admin->fresh()->is_active)->toBeFalse()
        ->and($this->admin->roleAssignments()->sole()->valid_to->toDateString())->toBe('2026-11-14');
});

it('CF-05: nadie se da de baja a sí mismo', function () {
    accountAssignment($this->rosa, Role::Admin);

    expect(fn () => deactivateAccount($this->admin, $this->admin))
        ->toThrow(ValidationException::class, UserAccountRules::SELF);

    expect($this->admin->fresh()->is_active)->toBeTrue()
        ->and(activitySince()->count())->toBe(0);
});

it('CF-05 y CF-09: no termina el nombramiento de docente, que maneja Faculty con su registro de docente', function () {
    $teaching = accountAssignment($this->rosa, Role::Docente);
    $headship = accountAssignment($this->rosa, Role::JefeDepartamento, ['org_unit_id' => $this->systems->id]);

    expect(deactivateAccount($this->rosa))->toBe(['ended' => 1, 'removed' => 0]);

    expect($teaching->fresh()->valid_to)->toBeNull()
        ->and($headship->fresh()->valid_to->toDateString())->toBe('2026-11-14')
        ->and($this->rosa->fresh()->is_active)->toBeFalse();
});

it('CF-S4: a un alumno no se le da de baja en el panel; su acceso sigue su estatus', function () {
    $student = User::factory()->withRole(Role::Alumno)->create();

    expect(fn () => deactivateAccount($student))
        ->toThrow(ValidationException::class, UserAccountRules::STUDENT);

    expect($student->fresh()->is_active)->toBeTrue()
        ->and(activitySince()->count())->toBe(0);
});

it('CF-05: no se le quita el nombramiento de alumno a quien también es personal', function () {
    $studentSeat = accountAssignment($this->rosa, Role::Alumno);
    accountAssignment($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);

    deactivateAccount($this->rosa);

    expect($studentSeat->fresh()->valid_to)->toBeNull()
        ->and($this->rosa->fresh()->is_active)->toBeFalse();
});

it('CF-05: si otra sesión borra un nombramiento a medio camino, el rechazo sale sobre la cuenta y no da de baja', function () {
    $coordination = accountAssignment($this->rosa, Role::Coordinador, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);
    RoleAssignment::retrieved(fn () => DB::table('role_assignments')->where('id', $coordination->id)->delete());

    try {
        deactivateAccount($this->rosa);
        $this->fail('Debió rechazar la baja.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['user' => ['Ese nombramiento ya no existe.']]);
    }

    expect($this->rosa->fresh()->is_active)->toBeTrue()
        ->and($this->rosa->fresh()->password)->not->toBeNull();
});

it('una cuenta ya dada de baja no se vuelve a dar de baja', function () {
    $this->rosa->forceFill(['is_active' => false])->save();

    expect(fn () => deactivateAccount($this->rosa))
        ->toThrow(ValidationException::class, UserAccountRules::ALREADY_INACTIVE);

    expect(activitySince()->count())->toBe(0);
});
