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
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| AppointRole nombra un puesto con las columnas que Role::scopeColumns() pide (AZ-S10, AZ-S11) y sin quitarle el puesto
| exclusivo a nadie (AZ-S7). El coordinador y el jefe de proyecto docente piden una modalidad activa y llevan campus
| solo si se elige (AZ-S17). Quién puede nombrar lo decide RoleAssignmentPolicy antes de llamarla.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    $this->systems = OrgUnit::find(Department::factory()->create(['name' => 'Sistemas y Computación'])->id);
    $this->planning = OrgUnit::factory()->create(['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación', 'kind' => OrgUnitKind::Subdireccion]);
    $this->academic = OrgUnit::find(app(OrgTree::class)->idOf(OrgTree::ACADEMIC));

    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

/**
 * Nombra con AppointRole. El coordinador y el jefe de proyecto docente van, si no se dice otra, en Presencial; `false`
 * no pasa modalidad.
 */
function appoint(User $user, Role $role, ?Campus $campus = null, ?OrgUnit $unit = null, ?string $career = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, Modality|false|null $modality = null): RoleAssignment
{
    $modality ??= in_array('modality_id', $role->scopeColumns(), true) ? modalityOf('PRES') : false;

    return app(AppointRole::class)->handle($user, $role, $campus, $unit, $career, $from ?? CarbonImmutable::parse('2026-11-03'), $to, $modality ?: null);
}

function expectInvalid(Closure $call, string $key, string $message): void
{
    expect($call)->toThrow(function (ValidationException $exception) use ($key, $message): void {
        expect($exception->errors()[$key] ?? null)->toBe([$message]);
    });
}

it('AZ-S17: nombra coordinador de una carrera en una modalidad y un campus, con bitácora y evento después del commit', function () {
    Event::fake([RoleAppointed::class]);

    $assignment = appoint($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id);

    expect($assignment->fresh())
        ->user_id->toBe($this->rosa->id)
        ->role->toBe(Role::Coordinador)
        ->campus_id->toBe($this->c2->id)
        ->modality_id->toBe(modalityOf('PRES')->id)
        ->career_id->toBe($this->isc->id)
        ->org_unit_id->toBeNull()
        ->and($assignment->valid_from->toDateString())->toBe('2026-11-03')
        ->and($assignment->valid_to)->toBeNull();

    $entry = activitySince()->where('event', 'created')->sole();

    expect($entry->subject->is($this->rosa))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->description)->toBe('Nombró a Rosa Ruiz como Coordinador de carrera · ISC · Presencial · Campus II')
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
    $modality = ['pres' => modalityOf('PRES'), 'ead' => modalityOf('EAD'), null => false];

    expectInvalid(
        fn () => appoint($this->rosa, $role, $campus[$scope[0]], $unit[$scope[1]], $career[$scope[2]], modality: $modality[$scope[3]]),
        $key,
        $message,
    );

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
})->with([
    'AZ-S17: coordinador sin modalidad' => [Role::Coordinador, ['c2', null, 'isc', null], 'modality', 'Elige la modalidad.'],
    'AZ-S17: coordinador en una modalidad inactiva' => [Role::Coordinador, ['c2', null, 'isc', 'ead'], 'modality', 'La modalidad EAD está inactiva.'],
    'coordinador sin carrera' => [Role::Coordinador, ['c2', null, null, 'pres'], 'career', 'Elige la carrera.'],
    'coordinador con unidad' => [Role::Coordinador, ['c2', 'systems', 'isc', 'pres'], 'unit', 'Este puesto no lleva unidad.'],
    'jefe con campus' => [Role::JefeDepartamento, ['c2', 'systems', null, null], 'campus', 'Este puesto no lleva campus.'],
    'jefe con modalidad' => [Role::JefeDepartamento, [null, 'systems', null, 'pres'], 'modality', 'Este puesto no lleva modalidad.'],
    'jefe sin unidad' => [Role::JefeDepartamento, [null, null, null, null], 'unit', 'Elige el departamento.'],
    'jefe sobre una subdirección' => [Role::JefeDepartamento, [null, 'planning', null, null], 'unit', 'Subdirección de Planeación no es un departamento académico ni Servicios Escolares.'],
    'jefe de proyecto docente sin unidad' => [Role::JefeProyectoDocente, ['c2', null, null, 'pres'], 'unit', 'Elige el departamento académico.'],
    'AZ-S17: jefe de proyecto docente sin modalidad' => [Role::JefeProyectoDocente, ['c2', 'systems', null, null], 'modality', 'Elige la modalidad.'],
    'subdirección sin unidad' => [Role::Subdireccion, [null, null, null, null], 'unit', 'Elige la subdirección.'],
    'subdirección sobre otra subdirección (AZ-S10)' => [Role::Subdireccion, [null, 'planning', null, null], 'unit', 'El puesto de Subdirección Académica va sobre la unidad Subdirección Académica.'],
    'docente con campus' => [Role::Docente, ['c2', null, null, null], 'campus', 'Este puesto no lleva campus.'],
    'admin con carrera' => [Role::Admin, [null, null, 'isc', null], 'career', 'Este puesto no lleva carrera.'],
    'admin con modalidad' => [Role::Admin, [null, null, null, 'pres'], 'modality', 'Este puesto no lleva modalidad.'],
]);

it('AZ-S17: el campus es opcional: nombra coordinador y jefe de proyecto docente de una modalidad en todos sus campus', function () {
    $coordinator = appoint($this->rosa, Role::Coordinador, null, null, $this->isc->id);
    $lead = appoint($this->rosa, Role::JefeProyectoDocente, null, $this->systems);

    expect($coordinator->fresh())->campus_id->toBeNull()->modality_id->toBe(modalityOf('PRES')->id)
        ->and($lead->fresh())->campus_id->toBeNull()->modality_id->toBe(modalityOf('PRES')->id)
        ->and(activitySince()->where('event', 'created')->pluck('description')->all())->toBe([
            'Nombró a Rosa Ruiz como Coordinador de carrera · ISC · Presencial · Todos los campus',
            'Nombró a Rosa Ruiz como Jefe de proyecto docente · Sistemas y Computación · Presencial · Todos los campus',
        ]);
});

it('MS8: vuelve a revisar la modalidad bajo el candado: si se desactiva antes de escribir, no nombra al coordinador', function () {
    $fired = false;

    Event::listen(TransactionBeginning::class, function () use (&$fired): void {
        if ($fired) {
            return;
        }

        $fired = true;
        DB::table('modalities')->where('code', 'PRES')->update(['is_active' => false]);
    });

    expectInvalid(fn () => appoint($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id), 'modality', 'La modalidad Presencial está inactiva.');

    expect($fired)->toBeTrue()
        ->and(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('AZ-S17: sin campus, la carrera debe ofrecerse en algún campus activo', function () {
    $nowhere = Career::factory()->create(['code' => 'IGE']);

    expectInvalid(fn () => appoint($this->rosa, Role::Coordinador, null, null, $nowhere->id), 'career', 'Esa carrera no se ofrece en ningún campus.');
});

it('D19: nombra jefe del Departamento de Servicios Escolares, que no es un departamento académico', function () {
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'parent_id' => $this->planning->id]);

    $assignment = appoint($this->rosa, Role::JefeDepartamento, null, $services);

    expect($assignment->fresh())->role->toBe(Role::JefeDepartamento)->org_unit_id->toBe($services->id)
        ->and(app(RoleAssignmentRules::class)->titularRoleOf($services))->toBe(Role::JefeDepartamento);
});

it('D19: los demás departamentos que no son académicos siguen sin jefe en Nexus', function () {
    $linkage = OrgUnit::factory()->create(['code' => 'VINCULACION', 'name' => 'Departamento de Gestión Tecnológica y Vinculación', 'kind' => OrgUnitKind::Departamento]);
    $inactiveServices = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'is_active' => false]);

    expectInvalid(fn () => appoint($this->rosa, Role::JefeDepartamento, null, $linkage), 'unit', 'Departamento de Gestión Tecnológica y Vinculación no es un departamento académico ni Servicios Escolares.');
    expectInvalid(fn () => appoint($this->rosa, Role::JefeProyectoDocente, $this->c2, $inactiveServices), 'unit', 'Departamento de Servicios Escolares no es un departamento académico.');
    expectInvalid(fn () => appoint($this->rosa, Role::JefeDepartamento, null, $inactiveServices), 'unit', 'Departamento de Servicios Escolares está inactiva.');

    expect(RoleAssignment::query()->where('user_id', $this->rosa->id)->exists())->toBeFalse();
});

it('junta todos los errores de alcance en una sola excepción', function () {
    expect(fn () => appoint($this->rosa, Role::Coordinador, null, $this->systems, null, modality: false))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe([
                'modality' => ['Elige la modalidad.'],
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
        'Rosa Ruiz ya es Coordinador de carrera · ISC · Presencial · Campus II en esas fechas.',
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
            'modality_id' => modalityOf('PRES')->id,
            'org_unit_id' => $this->systems->id,
            'valid_from' => '2026-11-01',
        ]);
    });

    expectInvalid(
        fn () => appoint($this->rosa, Role::JefeProyectoDocente, $this->c2, $this->systems),
        'role',
        'Rosa Ruiz ya es Jefe de proyecto docente · Sistemas y Computación · Presencial · Campus II en esas fechas.',
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
