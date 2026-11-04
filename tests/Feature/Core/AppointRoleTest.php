<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\RoleAppointed;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| AppointRole nombra un puesto con las columnas que Role::scopeColumns() pide (AZ-S10, AZ-S11) y sin quitarle el puesto
| exclusivo a nadie (AZ-S7). Quién puede nombrar lo decide RoleAssignmentPolicy antes de llamarla.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    $this->systems = OrgUnit::find(Department::factory()->create(['name' => 'Sistemas y Computación'])->id);
    $this->planning = OrgUnit::factory()->create(['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación', 'kind' => OrgUnitKind::Subdireccion]);
    $this->academic = OrgUnit::find(app(OrgTree::class)->idOf(OrgTree::ACADEMIC));

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function appoint(User $user, Role $role, ?Campus $campus = null, ?OrgUnit $unit = null, ?string $career = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): RoleAssignment
{
    return app(AppointRole::class)->handle($user, $role, $campus, $unit, $career, $from ?? CarbonImmutable::parse('2026-11-03'), $to);
}

function expectInvalid(Closure $call, string $key, string $message): void
{
    expect($call)->toThrow(function (ValidationException $exception) use ($key, $message): void {
        expect($exception->errors()[$key] ?? null)->toBe([$message]);
    });
}

it('nombra coordinador de una carrera en un campus, con bitácora y evento después del commit', function () {
    Event::fake([RoleAppointed::class]);

    $assignment = appoint($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id);

    expect($assignment->fresh())
        ->user_id->toBe($this->rosa->id)
        ->role->toBe(Role::Coordinador)
        ->campus_id->toBe($this->c2->id)
        ->career_id->toBe($this->isc->id)
        ->org_unit_id->toBeNull()
        ->and($assignment->valid_from->toDateString())->toBe('2026-11-03')
        ->and($assignment->valid_to)->toBeNull();

    $entry = activitySince()->where('event', 'created')->sole();

    expect($entry->subject->is($this->rosa))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->description)->toBe('Nombró a Rosa Ruiz como Coordinador de carrera · ISC · C2')
        ->and($entry->properties['role_assignment_id'])->toBe($assignment->id);

    Event::assertDispatched(RoleAppointed::class, fn (RoleAppointed $event): bool => $event->assignment->is($assignment));
});

it('no avisa si la transacción externa se revierte', function () {
    $heard = [];
    Event::listen(RoleAppointed::class, function (RoleAppointed $event) use (&$heard): void {
        $heard[] = $event;
    });

    try {
        DB::transaction(function (): void {
            appoint($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id);

            throw new RuntimeException('Falla algo después.');
        });
    } catch (RuntimeException) {
    }

    expect($heard)->toBe([])
        ->and(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('valida las columnas que pide cada puesto', function (Role $role, array $scope, string $key, string $message) {
    $campus = ['c2' => $this->c2, null => null];
    $unit = ['systems' => $this->systems, 'planning' => $this->planning, 'academic' => $this->academic, null => null];
    $career = ['isc' => $this->isc->id, null => null];

    expectInvalid(
        fn () => appoint($this->rosa, $role, $campus[$scope[0]], $unit[$scope[1]], $career[$scope[2]]),
        $key,
        $message,
    );

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
})->with([
    'coordinador sin campus' => [Role::Coordinador, [null, null, 'isc'], 'campus', 'Elige el campus.'],
    'coordinador sin carrera' => [Role::Coordinador, ['c2', null, null], 'career', 'Elige la carrera.'],
    'coordinador con unidad' => [Role::Coordinador, ['c2', 'systems', 'isc'], 'unit', 'Este puesto no lleva unidad.'],
    'jefe con campus' => [Role::JefeDepartamento, ['c2', 'systems', null], 'campus', 'Este puesto no lleva campus.'],
    'jefe sin unidad' => [Role::JefeDepartamento, [null, null, null], 'unit', 'Elige el departamento académico.'],
    'jefe sobre una subdirección' => [Role::JefeDepartamento, [null, 'planning', null], 'unit', 'Subdirección de Planeación no es un departamento académico.'],
    'jefe de proyecto docente sin campus' => [Role::JefeProyectoDocente, [null, 'systems', null], 'campus', 'Elige el campus.'],
    'subdirección sin unidad' => [Role::Subdireccion, [null, null, null], 'unit', 'Elige la subdirección.'],
    'subdirección sobre otra subdirección (AZ-S10)' => [Role::Subdireccion, [null, 'planning', null], 'unit', 'El puesto de Subdirección Académica va sobre la unidad Subdirección Académica.'],
    'docente con campus' => [Role::Docente, ['c2', null, null], 'campus', 'Este puesto no lleva campus.'],
    'admin con carrera' => [Role::Admin, [null, null, 'isc'], 'career', 'Este puesto no lleva carrera.'],
]);

it('junta todos los errores de alcance en una sola excepción', function () {
    expect(fn () => appoint($this->rosa, Role::Coordinador, null, $this->systems, null))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe([
                'campus' => ['Elige el campus.'],
                'unit' => ['Este puesto no lleva unidad.'],
                'career' => ['Elige la carrera.'],
            ]);
        });
});

it('rechaza un campus, una unidad o una carrera que ya no sirven', function () {
    $inactiveCampus = Campus::factory()->inactive()->create(['code' => 'C9']);
    $inactiveUnit = OrgUnit::find(Department::factory()->inactive()->create(['name' => 'Eléctrica'])->id);
    $elsewhere = Career::factory()->create(['code' => 'IGE']);

    expectInvalid(fn () => appoint($this->rosa, Role::Coordinador, $inactiveCampus, null, $this->isc->id), 'campus', 'El campus C9 está inactivo.');
    expectInvalid(fn () => appoint($this->rosa, Role::JefeProyectoDocente, $this->c2, $inactiveUnit), 'unit', 'Eléctrica está inactiva.');
    expectInvalid(fn () => appoint($this->rosa, Role::Coordinador, $this->c2, null, $elsewhere->id), 'career', 'Esa carrera no se ofrece en C2.');

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('rechaza fechas al revés y a un usuario inactivo', function () {
    expectInvalid(
        fn () => appoint($this->rosa, Role::Admin, from: CarbonImmutable::parse('2026-11-10'), to: CarbonImmutable::parse('2026-11-09')),
        'to',
        'La fecha de fin no puede ser anterior a la de inicio.',
    );

    $this->rosa->update(['is_active' => false]);

    expectInvalid(fn () => appoint($this->rosa, Role::Admin), 'user', 'El usuario está inactivo.');
    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('rechaza a la misma persona dos veces en el mismo puesto y alcance con fechas que se traslapan', function () {
    appoint($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-12-31'));

    expectInvalid(
        fn () => appoint($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id, CarbonImmutable::parse('2026-12-31')),
        'role',
        'Rosa Ruiz ya es Coordinador de carrera · ISC · C2 en esas fechas.',
    );

    $other = Career::factory()->create(['code' => 'IGE']);
    CareerCampus::factory()->create(['career_id' => $other->id, 'campus_id' => $this->c2->id]);

    expect(appoint($this->rosa, Role::Coordinador, $this->c2, null, $other->id))->toBeInstanceOf(RoleAssignment::class)
        ->and(appoint($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id, CarbonImmutable::parse('2027-01-01')))->toBeInstanceOf(RoleAssignment::class);
});

it('AZ-S7: un puesto exclusivo ocupado dice quién lo ocupa', function () {
    $beto = User::factory()->create(['name' => 'Beto Rentería']);
    RoleAssignment::factory()->for($beto)->create(['role' => Role::Subdireccion, 'org_unit_id' => $this->academic->id, 'valid_from' => '2025-09-01']);
    RoleAssignment::factory()->for($beto)->create(['role' => Role::JefeDepartamento, 'org_unit_id' => $this->systems->id, 'valid_from' => '2025-09-01']);

    expectInvalid(
        fn () => appoint($this->rosa, Role::Subdireccion, null, $this->academic),
        'unit',
        'Subdirección Académica ya tiene titular en esas fechas: Beto Rentería (desde 01/09/2025). Termina ese nombramiento antes de nombrar a otra persona.',
    );
    expectInvalid(
        fn () => appoint($this->rosa, Role::JefeDepartamento, null, $this->systems),
        'unit',
        'Sistemas y Computación ya tiene jefe en esas fechas: Beto Rentería (desde 01/09/2025). Termina ese nombramiento antes de nombrar a otra persona.',
    );

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('AZ-S7: si otra sesión gana el puesto entre la revisión y la escritura, el error es el mismo sin nombre', function () {
    $beto = User::factory()->create(['name' => 'Beto Rentería']);
    $fired = false;

    RoleAssignment::creating(function () use (&$fired, $beto): void {
        if ($fired) {
            return;
        }

        $fired = true;
        DB::table('role_assignments')->insert([
            'user_id' => $beto->id,
            'role' => Role::Subdireccion->value,
            'org_unit_id' => $this->academic->id,
            'valid_from' => '2026-11-01',
        ]);
    });

    expectInvalid(
        fn () => appoint($this->rosa, Role::Subdireccion, null, $this->academic),
        'unit',
        'Subdirección Académica ya tiene titular en esas fechas. Termina ese nombramiento antes de nombrar a otra persona.',
    );

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('si otra sesión nombra a la misma persona jefe de proyecto docente entre la revisión y la escritura, el error es el de duplicado', function () {
    $fired = false;

    RoleAssignment::creating(function () use (&$fired): void {
        if ($fired) {
            return;
        }

        $fired = true;
        DB::table('role_assignments')->insert([
            'user_id' => $this->rosa->id,
            'role' => Role::JefeProyectoDocente->value,
            'campus_id' => $this->c2->id,
            'org_unit_id' => $this->systems->id,
            'valid_from' => '2026-11-01',
        ]);
    });

    expectInvalid(
        fn () => appoint($this->rosa, Role::JefeProyectoDocente, $this->c2, $this->systems),
        'role',
        'Rosa Ruiz ya es Jefe de proyecto docente · Sistemas y Computación · C2 en esas fechas.',
    );

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('el jefe de proyecto docente admite varios a la vez en el mismo departamento y campus', function () {
    $beto = User::factory()->create(['name' => 'Beto Rentería']);

    appoint($this->rosa, Role::JefeProyectoDocente, $this->c2, $this->systems);
    appoint($beto, Role::JefeProyectoDocente, $this->c2, $this->systems);

    expect(RoleAssignment::query()->where('role', Role::JefeProyectoDocente)->where('org_unit_id', $this->systems->id)->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->rosa->id, $beto->id]);
});

it('quien se nombra a sí mismo ve su puesto nuevo en la misma petición', function () {
    $teacher = User::factory()->withRole(Role::Docente)->create();
    $this->actingAs($teacher);
    $context = app(Actor::class);

    expect($context->heldRoles())->toBe([Role::Docente]);

    appoint($teacher, Role::Coordinador, $this->c2, null, $this->isc->id);

    expect($context->heldRoles())->toContain(Role::Coordinador);
});
