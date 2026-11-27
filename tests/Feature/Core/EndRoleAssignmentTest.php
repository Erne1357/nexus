<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Enums\AssignmentEnding;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\RoleAssignmentEnded;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| EndRoleAssignment termina un nombramiento sin alargarlo nunca (regla de CloseRoleScopes) y cuida que quede otro
| administrador (AZ-S12). Quién puede terminar lo decide RoleAssignmentPolicy antes de llamarla.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isc = Career::factory()->create(['code' => 'ISC']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function coordinatorAssignment(User $user, array $attributes = []): RoleAssignment
{
    return RoleAssignment::factory()->for($user)->create([
        'role' => Role::Coordinador,
        'campus_id' => test()->c2->id,
        'career_id' => test()->isc->id,
        'valid_from' => '2026-09-01',
        'valid_to' => null,
        ...$attributes,
    ]);
}

function endOn(RoleAssignment $assignment, string $day): AssignmentEnding
{
    return app(EndRoleAssignment::class)->handle($assignment, CarbonImmutable::parse($day));
}

it('termina un nombramiento vigente en el último día, con bitácora y evento después del commit', function () {
    $assignment = coordinatorAssignment($this->rosa);
    $heard = [];
    Event::listen(RoleAssignmentEnded::class, function (RoleAssignmentEnded $event) use (&$heard): void {
        $heard[] = $event;
    });

    try {
        DB::transaction(function () use ($assignment): void {
            endOn($assignment, '2026-12-15');

            throw new RuntimeException('Falla algo después.');
        });
    } catch (RuntimeException) {
    }

    expect($heard)->toBe([])
        ->and($assignment->fresh()->valid_to)->toBeNull();

    expect(endOn($assignment, '2026-12-15'))->toBe(AssignmentEnding::Ended)
        ->and($assignment->fresh()->valid_to->toDateString())->toBe('2026-12-15')
        ->and($heard)->toHaveCount(1)
        ->and($heard[0]->ending)->toBe(AssignmentEnding::Ended);

    $entry = activitySince()->where('event', 'updated')->sole();

    expect($entry->subject->is($this->rosa))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->description)->toBe('Terminó el nombramiento de Rosa Ruiz como Coordinador de carrera · ISC · Presencial · Campus II')
        ->and($entry->properties['role_assignment_id'])->toBe($assignment->id)
        ->and($entry->attribute_changes['attributes'])->toBe(['valid_to' => '2026-12-15'])
        ->and($entry->attribute_changes['old'])->toBe(['valid_to' => null]);
});

it('quita uno que todavía no empieza si termina antes de su inicio', function () {
    $assignment = coordinatorAssignment($this->rosa, ['valid_from' => '2026-12-01']);
    Event::fake([RoleAssignmentEnded::class]);

    expect(endOn($assignment, '2026-11-30'))->toBe(AssignmentEnding::Removed)
        ->and(RoleAssignment::query()->whereKey($assignment->id)->exists())->toBeFalse();

    $entry = activitySince()->where('event', 'deleted')->sole();

    expect($entry->description)->toBe('Quitó el nombramiento de Rosa Ruiz como Coordinador de carrera · ISC · Presencial · Campus II, que todavía no empezaba')
        ->and($entry->properties['role_assignment_id'])->toBe($assignment->id)
        ->and($entry->attribute_changes['old'])->toBe([
            'role' => 'coordinador',
            'campus_id' => $this->c2->id,
            'org_unit_id' => null,
            'career_id' => $this->isc->id,
            'valid_from' => '2026-12-01',
            'valid_to' => null,
        ]);

    Event::assertDispatched(RoleAssignmentEnded::class, fn (RoleAssignmentEnded $event): bool => $event->ending === AssignmentEnding::Removed);
});

it('nunca alarga: uno que ya termina antes no cambia ni deja bitácora', function () {
    $assignment = coordinatorAssignment($this->rosa, ['valid_to' => '2026-11-30']);
    Event::fake([RoleAssignmentEnded::class]);

    expect(endOn($assignment, '2026-12-15'))->toBe(AssignmentEnding::Unchanged)
        ->and(endOn($assignment, '2026-11-30'))->toBe(AssignmentEnding::Unchanged)
        ->and($assignment->fresh()->valid_to->toDateString())->toBe('2026-11-30')
        ->and(activitySince()->count())->toBe(0);

    Event::assertNotDispatched(RoleAssignmentEnded::class);
});

it('quita uno que empieza hoy si el último día es anterior a su inicio (regla de CloseRoleScopes)', function () {
    $assignment = coordinatorAssignment($this->rosa, ['valid_from' => '2026-11-03']);

    expect(endOn($assignment, '2026-11-02'))->toBe(AssignmentEnding::Removed)
        ->and(RoleAssignment::query()->whereKey($assignment->id)->exists())->toBeFalse()
        ->and(activitySince()->where('event', 'deleted')->sole()->description)->toBe('Quitó el nombramiento de Rosa Ruiz como Coordinador de carrera · ISC · Presencial · Campus II, que empezaba hoy');
});

it('rechaza un último día anterior al inicio de uno que ya empezó', function () {
    $assignment = coordinatorAssignment($this->rosa, ['valid_from' => '2026-08-01']);

    expect(fn () => endOn($assignment, '2026-07-31'))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors()['lastDay'])->toBe(['La fecha de fin no puede ser anterior al inicio (01/08/2026).']);
    });

    expect($assignment->fresh()->valid_to)->toBeNull()
        ->and(activitySince()->count())->toBe(0);
});

it('AZ-S12: no termina el único nombramiento de Administración que seguiría vigente', function () {
    $mine = $this->admin->roleAssignments()->where('role', Role::Admin)->sole();
    $message = 'Es el único nombramiento de Administración que seguiría vigente; nombra a otra persona antes de terminarlo.';
    $fails = fn () => expect(fn () => endOn($mine, '2026-12-15'))->toThrow(function (ValidationException $exception) use ($message): void {
        expect($exception->errors()['lastDay'])->toBe([$message]);
    });

    $fails();

    $inactive = User::factory()->admin()->create(['is_active' => false]);
    $fails();

    $dies = User::factory()->admin()->create();
    $dies->roleAssignments()->update(['valid_to' => '2026-12-15']);
    $fails();

    expect($mine->fresh()->valid_to)->toBeNull();

    $other = User::factory()->admin()->create();

    expect(endOn($mine, '2026-12-15'))->toBe(AssignmentEnding::Ended)
        ->and($mine->fresh()->valid_to->toDateString())->toBe('2026-12-15');
});

it('el admin puede terminar su propio puesto si queda otro admin vigente', function () {
    $mine = $this->admin->roleAssignments()->where('role', Role::Admin)->sole();
    User::factory()->admin()->create();

    expect(endOn($mine, '2026-12-15'))->toBe(AssignmentEnding::Ended)
        ->and($mine->fresh()->valid_to->toDateString())->toBe('2026-12-15');
});

it('nadie termina al último admin vigente', function () {
    $mine = $this->admin->roleAssignments()->where('role', Role::Admin)->sole();
    $future = User::factory()->admin()->create();
    $future->roleAssignments()->update(['valid_from' => '2027-01-10']);

    expect(fn () => endOn($mine, '2026-12-15'))->toThrow(ValidationException::class);

    $outsider = User::factory()->create();
    $outsider->roleAssignments()->create(['role' => Role::Admin, 'valid_from' => '2026-01-01', 'valid_to' => null]);
    $this->actingAs($outsider);
    $future->roleAssignments()->update(['valid_from' => '2026-12-16']);

    expect(endOn($mine, '2026-12-15'))->toBe(AssignmentEnding::Ended);

    $theirs = $outsider->roleAssignments()->sole();
    $future->roleAssignments()->update(['valid_to' => '2026-12-20']);

    expect(fn () => endOn($theirs, '2026-12-17'))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors()['lastDay'])->toBe(['El 21/12/2026 nadie tendría un nombramiento de Administración vigente; nombra a otra persona antes de terminarlo.']);
    })->and(endOn($future->roleAssignments()->sole(), '2026-12-17'))->toBe(AssignmentEnding::Ended)
        ->and($theirs->fresh()->valid_to)->toBeNull();
});

it('AZ-S12: dos terminaciones con fecha futura no dejan ningún día sin admin', function () {
    $ana = $this->admin->roleAssignments()->where('role', Role::Admin)->sole();
    $beto = User::factory()->admin()->create(['name' => 'Beto Bravo'])->roleAssignments()->sole();

    expect(endOn($beto, '2026-11-30'))->toBe(AssignmentEnding::Ended)
        ->and(fn () => endOn($ana, '2026-11-15'))->toThrow(function (ValidationException $exception): void {
            expect($exception->errors()['lastDay'])->toBe(['El 01/12/2026 nadie tendría un nombramiento de Administración vigente; nombra a otra persona antes de terminarlo.']);
        })
        ->and($ana->fresh()->valid_to)->toBeNull();
});

it('AZ-S12: no quita al sucesor programado si deja días sin admin', function () {
    $ana = $this->admin->roleAssignments()->where('role', Role::Admin)->sole();
    $ana->update(['valid_to' => '2026-12-31']);
    $successor = RoleAssignment::factory()->for(User::factory()->create(['name' => 'Beto Bravo']))->create(['role' => Role::Admin, 'valid_from' => '2027-01-01']);

    expect(fn () => endOn($successor, '2026-11-03'))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors()['lastDay'])->toBe(['Es el único nombramiento de Administración que seguiría vigente; nombra a otra persona antes de terminarlo.']);
    })->and(RoleAssignment::find($successor->id))->not->toBeNull();

    User::factory()->admin()->create();

    expect(endOn($successor, '2026-11-03'))->toBe(AssignmentEnding::Removed);
});

it('AZ-S12: toma el candado de los puestos de Administración antes que el del renglón, para que dos terminaciones se esperen', function () {
    $mine = $this->admin->roleAssignments()->where('role', Role::Admin)->sole();
    User::factory()->admin()->create();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    endOn($mine, '2026-12-15');

    $advisory = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'));
    $row = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'for update'));

    expect($advisory)->toBeInt()->and($row)->toBeInt()->and($advisory)->toBeLessThan($row);
});

it('un nombramiento borrado en otra sesión es un error de validación, no un 500', function () {
    $assignment = coordinatorAssignment($this->rosa);
    RoleAssignment::query()->whereKey($assignment->id)->delete();

    expect(fn () => endOn($assignment, '2026-12-15'))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors()['assignment'])->toBe(['Ese nombramiento ya no existe.']);
    });
});

it('sube authz_version del usuario', function () {
    $assignment = coordinatorAssignment($this->rosa);
    $before = $this->rosa->fresh()->authz_version;

    endOn($assignment, '2026-12-15');

    expect($this->rosa->fresh()->authz_version)->toBeGreaterThan($before);
});
