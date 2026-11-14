<?php

use App\Modules\Core\Actions\UpdateUserProfile;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\UserProfileUpdated;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateUserProfile, «Editar datos» de una cuenta (CF-S3): el nombre y el correo institucional (opcional, en minúsculas,
| único sin importar mayúsculas y del dominio `nexus.institutional_email_domain`). El usuario no se edita. Cambiar el
| correo deja sin efecto la invitación pendiente y el enlace para restablecer (AC-17), y el correo nuevo queda sin
| confirmar. No edita a un alumno (CF-S4).
*/

beforeEach(function () {
    $this->travelTo('2026-11-14 10:00:00');

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
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
 * @param  array<string, mixed>  $data
 */
function updateProfile(User $user, array $data): User
{
    return app(UpdateUserProfile::class)->handle($user, $data, test()->admin);
}

it('CF-S3: cambia el nombre y el correo, en minúsculas, con bitácora de lo de antes y lo de después', function () {
    $updated = updateProfile($this->rosa, ['name' => '  Rosa Ruiz Almanza ', 'email' => ' Rosa.Almanza@CDJUAREZ.tecnm.mx ']);

    expect($updated->name)->toBe('Rosa Ruiz Almanza')
        ->and($updated->email)->toBe('rosa.almanza@cdjuarez.tecnm.mx')
        ->and($this->rosa->fresh()->email)->toBe('rosa.almanza@cdjuarez.tecnm.mx');

    $entry = activitySince()->sole();

    expect($entry->event)->toBe('updated')
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->attribute_changes['attributes'])->toMatchArray(['name' => 'Rosa Ruiz Almanza', 'email' => 'rosa.almanza@cdjuarez.tecnm.mx'])
        ->and($entry->attribute_changes['old'])->toMatchArray(['name' => 'Rosa Ruiz', 'email' => 'rosa.ruiz@cdjuarez.tecnm.mx'])
        ->and($entry->description)->toBe('Editó los datos de RURR800101AB1');
});

it('CF-S3: el usuario no se edita aunque llegue en los datos', function () {
    updateProfile($this->rosa, ['username' => 'OTRO', 'name' => 'Rosa R.']);

    expect($this->rosa->fresh()->username)->toBe('RURR800101AB1')
        ->and($this->rosa->fresh()->name)->toBe('Rosa R.');
});

it('CF-S3: el correo debe ser del dominio institucional', function (string $email) {
    expect(fn () => updateProfile($this->rosa, ['email' => $email]))
        ->toThrow(ValidationException::class, 'El correo institucional debe terminar en @cdjuarez.tecnm.mx.');

    expect($this->rosa->fresh()->email)->toBe('rosa.ruiz@cdjuarez.tecnm.mx')
        ->and(activitySince()->count())->toBe(0);
})->with(['rosa@gmail.com', 'rosa@itcj.edu.mx', 'rosa@sub.cdjuarez.tecnm.mx', 'no-es-correo']);

it('CF-S3: el correo es único sin importar mayúsculas', function () {
    User::factory()->create(['email' => 'Beto.Bravo@cdjuarez.tecnm.mx']);

    expect(fn () => updateProfile($this->rosa, ['email' => 'beto.bravo@cdjuarez.tecnm.mx']))
        ->toThrow(ValidationException::class, 'Otro usuario ya tiene el correo beto.bravo@cdjuarez.tecnm.mx.');

    expect($this->rosa->fresh()->email)->toBe('rosa.ruiz@cdjuarez.tecnm.mx');
});

it('CF-S3: si otra sesión toma el correo entre la revisión y la escritura, es el mismo error', function () {
    User::updating(fn () => DB::table('users')->insert(['username' => 'otra-sesion', 'name' => 'Otra', 'email' => 'GANA@cdjuarez.tecnm.mx']));

    expect(fn () => updateProfile($this->rosa, ['email' => 'gana@cdjuarez.tecnm.mx']))
        ->toThrow(ValidationException::class, 'Otro usuario ya tiene el correo gana@cdjuarez.tecnm.mx.');

    expect($this->rosa->fresh()->email)->toBe('rosa.ruiz@cdjuarez.tecnm.mx')
        ->and(activitySince()->count())->toBe(0);
});

it('CF-S3 y AC-17: cambiar el correo deja sin efecto el enlace para restablecer y queda sin confirmar', function () {
    $tokens = app(PasswordResetTokens::class);
    $token = $tokens->create($this->rosa);

    updateProfile($this->rosa, ['email' => 'rosa.almanza@cdjuarez.tecnm.mx']);

    $rosa = $this->rosa->fresh();

    expect(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and($tokens->opens($rosa, $token))->toBeFalse()
        ->and($rosa->email_verified_at)->toBeNull();
});

it('CF-S3: cambiar el correo de quien tiene una invitación pendiente la deja sin efecto', function () {
    $this->rosa->forceFill(['password' => null, 'email_verified_at' => null, 'access_invited_at' => now()])->save();

    updateProfile($this->rosa, ['email' => 'rosa.almanza@cdjuarez.tecnm.mx']);

    expect($this->rosa->fresh()->access_invited_at)->toBeNull()
        ->and(activitySince()->sole()->attribute_changes['old'])->toHaveKey('access_invited_at');
});

it('CF-S3: vaciar el correo lo quita, y el nombre solo no toca el correo ni su confirmación', function () {
    updateProfile($this->rosa, ['name' => 'Rosa R.']);

    expect($this->rosa->fresh()->email_verified_at)->not->toBeNull();

    updateProfile($this->rosa, ['email' => '  ']);

    expect($this->rosa->fresh()->email)->toBeNull();
});

it('sin cambios no escribe, no deja bitácora ni avisa', function () {
    Event::fake([UserProfileUpdated::class]);

    updateProfile($this->rosa, ['name' => 'Rosa Ruiz', 'email' => 'ROSA.RUIZ@cdjuarez.tecnm.mx']);

    expect(activitySince()->count())->toBe(0);
    Event::assertNotDispatched(UserProfileUpdated::class);
});

it('avisa con UserProfileUpdated después del commit', function () {
    Event::fake([UserProfileUpdated::class]);

    updateProfile($this->rosa, ['name' => 'Rosa R.']);

    Event::assertDispatched(UserProfileUpdated::class, fn (UserProfileUpdated $event): bool => $event->user->is($this->rosa));
});

it('CF-S4: no edita la cuenta de un alumno', function () {
    $student = User::factory()->withRole(Role::Alumno)->create(['name' => 'Alumno Uno']);

    expect(fn () => updateProfile($student, ['name' => 'Otro']))->toThrow(ValidationException::class, UserAccountRules::STUDENT);

    expect($student->fresh()->name)->toBe('Alumno Uno');
});
