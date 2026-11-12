<?php

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\SiiNip;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Jobs\SyncStudentAccountsJob;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Rules\InstitutionalEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

/*
| Cuentas de alumno con su NIP del SII (AC-10 a AC-13): un usuario por alumno, activo si es vigente, con el NIP como
| contraseña mientras no la cambie, el puesto `alumno` y su correo institucional.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
});

/**
 * @param  array<string, string>  $nips
 * @return array{created: int, updated: int, unchanged: int, missing: int, conflicts: list<string>, emails_taken: list<string>}
 */
function syncStudentAccounts(array $nips, ?User $actor = null): array
{
    return app(SyncStudentAccounts::class)->handle($nips, $actor);
}

function accountOf(string $controlNumber): ?User
{
    return Student::withTrashed()->where('control_number', $controlNumber)->first()?->user()->first();
}

it('AC-10: crea el usuario del alumno vigente con su NIP, el puesto de alumno y lo liga', function () {
    $student = Student::factory()->create(['control_number' => '21110001', 'name' => 'Ana López']);

    $summary = syncStudentAccounts(['21110001' => '4821']);

    $user = $student->fresh()->user;
    expect($summary)->toMatchArray(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'conflicts' => []])
        ->and($user->username)->toBe('21110001')
        ->and($user->name)->toBe('Ana López')
        ->and($user->is_active)->toBeTrue()
        ->and($user->must_change_password)->toBeFalse()
        ->and($user->password_origin)->toBe(PasswordOrigin::SiiNip)
        ->and(Hash::check('4821', $user->password))->toBeTrue()
        ->and($user->roleAssignments()->current()->pluck('role')->all())->toBe([Role::Alumno])
        ->and($user->roleAssignments()->first()->only(['campus_id', 'org_unit_id', 'career_id']))->toBe(['campus_id' => null, 'org_unit_id' => null, 'career_id' => null]);
});

it('AC-10: el alumno entra con su número de control y su NIP; el que no es vigente, no', function () {
    Student::factory()->create(['control_number' => '21110001']);
    Student::factory()->inactive()->create(['control_number' => '21110002']);
    syncStudentAccounts(['21110001' => '4821', '21110002' => '1357']);

    $this->post('/login', ['username' => '21110002', 'password' => '1357'])->assertSessionHasErrors(['username' => __('auth.failed')]);
    $this->assertGuest();

    $this->post('/login', ['username' => '21110001', 'password' => '4821'])->assertRedirect('/');
    $this->assertAuthenticatedAs(accountOf('21110001'));
});

it('AC-10: al volverse vigente se activa y al dejar de serlo, o al borrarse, se desactiva', function () {
    $student = Student::factory()->inactive()->create(['control_number' => '21110001']);
    syncStudentAccounts(['21110001' => '4821']);
    expect(accountOf('21110001')->is_active)->toBeFalse();

    $student->update(['is_active' => true]);
    expect(syncStudentAccounts(['21110001' => '4821'])['updated'])->toBe(1)
        ->and(accountOf('21110001')->is_active)->toBeTrue();

    $student->update(['is_active' => false]);
    syncStudentAccounts(['21110001' => '4821']);
    expect(accountOf('21110001')->is_active)->toBeFalse();

    $student->update(['is_active' => true]);
    $student->delete();
    syncStudentAccounts(['21110001' => '4821']);
    expect(accountOf('21110001')->is_active)->toBeFalse();
});

it('AC-10: un NIP vacío deja al usuario sin acceso, también al que ya tenía el del SII', function () {
    Student::factory()->create(['control_number' => '21110001']);
    Student::factory()->create(['control_number' => '21110002']);

    syncStudentAccounts(['21110001' => '', '21110002' => '4821']);
    syncStudentAccounts(['21110002' => '']);

    foreach (['21110001', '21110002'] as $controlNumber) {
        expect(accountOf($controlNumber)->password)->toBeNull()
            ->and(accountOf($controlNumber)->password_origin)->toBeNull();
    }
});

it('AC-10: no toca un usuario con su número de control que no es suyo, y salta lo que no es alumno en Nexus', function () {
    Student::factory()->create(['control_number' => '21110001']);
    $other = User::factory()->create(['username' => '21110001', 'name' => 'Otra persona']);

    $summary = syncStudentAccounts(['21110001' => '4821', '29999999' => '1111']);

    expect($summary)->toMatchArray(['created' => 0, 'missing' => 1, 'conflicts' => ['21110001']])
        ->and(Student::query()->where('control_number', '21110001')->value('user_id'))->toBeNull()
        ->and($other->fresh()->name)->toBe('Otra persona')
        ->and(Hash::check('password', $other->fresh()->password))->toBeTrue()
        ->and(User::query()->where('username', '29999999')->exists())->toBeFalse();
});

it('AC-11: un NIP de 1 a 3 dígitos se guarda con ceros a 4', function () {
    Student::factory()->create(['control_number' => '21110001']);

    syncStudentAccounts(['21110001' => '42']);

    expect(Hash::check('0042', accountOf('21110001')->password))->toBeTrue();
});

it('AC-12: una nueva corrida pone al día el NIP del SII, pero no la contraseña que el alumno o el admin pusieron', function (PasswordOrigin $origin) {
    Student::factory()->create(['control_number' => '21110001']);
    Student::factory()->create(['control_number' => '21110002']);
    syncStudentAccounts(['21110001' => '4821', '21110002' => '4821']);
    accountOf('21110002')->forceFill(['password' => 'Propia-2027', 'password_origin' => $origin])->save();

    syncStudentAccounts(['21110001' => '9753', '21110002' => '9753']);

    expect(Hash::check('9753', accountOf('21110001')->password))->toBeTrue()
        ->and(Hash::check('Propia-2027', accountOf('21110002')->password))->toBeTrue()
        ->and(accountOf('21110002')->password_origin)->toBe($origin);
})->with([PasswordOrigin::User, PasswordOrigin::Default]);

it('AC-12: repetir la corrida con los mismos datos no escribe nada ni cierra sesiones', function () {
    Student::factory()->create(['control_number' => '21110001']);
    syncStudentAccounts(['21110001' => '4821']);
    $before = accountOf('21110001');
    $logged = Activity::query()->count();

    $summary = syncStudentAccounts(['21110001' => '4821']);

    $after = accountOf('21110001');
    expect($summary)->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => 1])
        ->and($after->password)->toBe($before->password)
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and($after->roleAssignments()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe($logged + 1);
});

it('AC-13: el correo institucional sale del número de control', function (string $controlNumber, ?string $email) {
    expect(InstitutionalEmail::of($controlNumber))->toBe($email);
})->with([
    'licenciatura' => ['21110001', 'l21110001@cdjuarez.tecnm.mx'],
    'nueve dígitos' => ['211100012', 'l211100012@cdjuarez.tecnm.mx'],
    'posgrado con M' => ['M21110001', 'm21110001@cdjuarez.tecnm.mx'],
    'posgrado con D' => ['D21110001', 'd21110001@cdjuarez.tecnm.mx'],
    'dos letras' => ['MC2111000', 'mc2111000@cdjuarez.tecnm.mx'],
    'con espacio' => ['2111 0001', null],
]);

it('AC-13: el usuario toma el correo institucional; si otro usuario lo tiene, se queda sin correo y se reporta', function () {
    Student::factory()->create(['control_number' => 'M21110001']);
    Student::factory()->create(['control_number' => '21110002']);
    User::factory()->create(['email' => 'L21110002@cdjuarez.tecnm.mx']);

    $summary = syncStudentAccounts(['M21110001' => '4821', '21110002' => '4821']);

    expect(accountOf('M21110001')->email)->toBe('m21110001@cdjuarez.tecnm.mx')
        ->and(accountOf('M21110001')->email_verified_at)->toBeNull()
        ->and(accountOf('21110002')->email)->toBeNull()
        ->and($summary['emails_taken'])->toBe(['21110002'])
        ->and($summary['created'])->toBe(2);
});

it('AC-10: la bitácora registra el alta, los cambios y el bloque a nombre de quien corrió, nunca el NIP ni su hash', function () {
    $actor = User::factory()->create();
    $student = Student::factory()->inactive()->create(['control_number' => '21110001']);
    $base = (int) Activity::query()->max('id');
    syncStudentAccounts(['21110001' => '4821'], $actor);
    $student->update(['is_active' => true]);
    syncStudentAccounts(['21110001' => '9753'], $actor);

    $hash = accountOf('21110001')->password;
    $entries = Activity::query()->where('id', '>', $base)->orderBy('id')->get();
    $dump = $entries->map(fn (Activity $entry): string => $entry->description.json_encode($entry->properties))->implode("\n");

    expect($entries->pluck('event')->all())->toBe(['created', 'student-accounts-synced', 'updated', 'student-accounts-synced'])
        ->and($entries->every(fn (Activity $entry): bool => $entry->causer_id === $actor->id))->toBeTrue()
        ->and($entries[2]->description)->toBe('Puso al día el usuario del alumno 21110001: lo activó, su NIP del SII')
        ->and($dump)->not->toContain('4821')
        ->and($dump)->not->toContain('9753')
        ->and($dump)->not->toContain($hash);
});

it('AC-10: el bloque viaja cifrado en la cola: ni los números de control ni los NIP van en claro', function () {
    config(['queue.default' => 'database']);

    SyncStudentAccountsJob::dispatch(['21119876' => '9381']);

    $payload = DB::table('jobs')->value('payload');
    $command = json_decode($payload, true)['data']['command'];

    expect($payload)->not->toContain('21119876')
        ->and($payload)->not->toContain('9381')
        ->and(unserialize(decrypt($command))->nips)->toBe(['21119876' => '9381']);
});

it('AC-10: el job del bloque crea las cuentas al correr', function () {
    Student::factory()->create(['control_number' => '21110001']);

    SyncStudentAccountsJob::dispatchSync(['21110001' => '4821']);

    expect(Hash::check('4821', accountOf('21110001')->password))->toBeTrue()
        ->and(SiiNip::rounds())->toBe(4);
});
