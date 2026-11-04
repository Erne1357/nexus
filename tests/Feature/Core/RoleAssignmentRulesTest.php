<?php

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use Carbon\CarbonImmutable;

/*
| RoleAssignmentRules: qué unidad admite cada puesto (AZ-S10), quién es el titular de una unidad y qué puestos se
| manejan en otra pantalla (AZ-S11).
*/

it('allowsUnit y titularRoleOf: la Subdirección solo va en Subdirección Académica; el jefe, en un departamento académico', function () {
    $rules = app(RoleAssignmentRules::class);
    $academic = OrgUnit::find(app(OrgTree::class)->idOf(OrgTree::ACADEMIC));
    $planning = OrgUnit::factory()->create(['kind' => OrgUnitKind::Subdireccion]);
    $department = OrgUnit::factory()->create(['kind' => OrgUnitKind::DepartamentoAcademico]);
    $office = OrgUnit::factory()->create(['kind' => OrgUnitKind::Oficina]);

    expect($rules->allowsUnit(Role::Subdireccion, $academic))->toBeTrue()
        ->and($rules->allowsUnit(Role::Subdireccion, $planning))->toBeFalse()
        ->and($rules->allowsUnit(Role::JefeDepartamento, $department))->toBeTrue()
        ->and($rules->allowsUnit(Role::JefeProyectoDocente, $department))->toBeTrue()
        ->and($rules->allowsUnit(Role::Coordinador, $department))->toBeFalse()
        ->and($rules->titularRoleOf($academic))->toBe(Role::Subdireccion)
        ->and($rules->titularRoleOf($department))->toBe(Role::JefeDepartamento)
        ->and($rules->titularRoleOf($planning))->toBeNull()
        ->and($rules->titularRoleOf($office))->toBeNull();
});

it('managedElsewhere: solo el docente se maneja en otra parte (AZ-S11)', function () {
    $rules = app(RoleAssignmentRules::class);

    expect($rules->managedElsewhere(Role::Docente))->toBe('El puesto de docente lo da la ficha del docente: se activa y se desactiva con el docente.')
        ->and($rules->managedElsewhere(Role::JefeDepartamento))->toBeNull()
        ->and($rules->managedElsewhere(Role::JefeProyectoDocente))->toBeNull()
        ->and($rules->managedElsewhere(Role::Admin))->toBeNull()
        ->and($rules->managedElsewhere(Role::Subdireccion))->toBeNull()
        ->and($rules->managedElsewhere(Role::Coordinador))->toBeNull();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function adminSeat(User $user, string $from, ?string $to = null, array $attributes = []): RoleAssignment
{
    return RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Admin, 'valid_from' => $from, 'valid_to' => $to, ...$attributes]);
}

describe('earliestLastDay (AZ-S12)', function () {
    beforeEach(function () {
        $this->travelTo('2026-11-03 10:00:00');
        $this->rules = app(RoleAssignmentRules::class);
    });

    it('da el primer último día que no deja días sin admin', function (array $ana, array $others, ?string $expected) {
        $ana = adminSeat(User::factory()->create(), ...$ana);

        foreach ($others as $other) {
            adminSeat(User::factory()->create(['is_active' => $other[2] ?? true]), $other[0], $other[1] ?? null);
        }

        $day = $this->rules->earliestLastDay($ana);

        expect($day?->toDateString())->toBe($expected);

        if ($day !== null) {
            expect($this->rules->adminGap($ana, $day))->toBeNull();

            if ($day->isAfter(today())) {
                expect($this->rules->adminGap($ana, $day->subDay()))->not->toBeNull();
            }
        }
    })->with([
        'otro sin fin' => [['2026-08-01'], [['2026-01-01']], '2026-11-03'],
        'único sucesor programado' => [['2026-08-01'], [['2026-12-01']], '2026-11-30'],
        'sucesor con fin' => [['2026-08-01'], [['2026-12-01', '2027-01-31']], null],
        'Ana termina el 2026-12-31' => [['2026-08-01', '2026-12-31'], [['2027-01-01']], null],
        'Ana programada desde 2026-12-20' => [['2026-12-20'], [['2026-01-01', '2026-12-09'], ['2026-12-11']], '2026-11-03'],
        'otro admin con usuario inactivo' => [['2026-08-01'], [['2026-01-01', null, false]], null],
        'sin sucesor' => [['2026-08-01'], [], null],
        'sucesor desde mañana' => [['2026-08-01'], [['2026-11-04']], '2026-11-03'],
        'Ana termina antes de que empiece el sucesor' => [['2026-08-01', '2026-11-20'], [['2026-12-01']], null],
    ]);

    it('un nombramiento de otro puesto termina hoy', function () {
        $coordinator = RoleAssignment::factory()->create(['role' => Role::Docente, 'valid_from' => '2026-08-01']);

        expect($this->rules->earliestLastDay($coordinator)->toDateString())->toBe('2026-11-03');
    });

    it('adminGap da el día exacto aunque sea el siguiente al último día, si otro admin empieza después', function () {
        $ana = adminSeat(User::factory()->create(), '2026-08-01');

        expect($this->rules->adminGap($ana, CarbonImmutable::parse('2026-11-15')))
            ->toBe('Es el único nombramiento de Administración que seguiría vigente; nombra a otra persona antes de terminarlo.');

        adminSeat(User::factory()->create(), '2026-12-01');

        expect($this->rules->adminGap($ana, CarbonImmutable::parse('2026-11-15')))
            ->toBe('El 16/11/2026 nadie tendría un nombramiento de Administración vigente; nombra a otra persona antes de terminarlo.');
    });

    it('endBlocker: el docente se maneja en otra parte; Administración solo se bloquea si ningún último día sirve', function () {
        $docente = RoleAssignment::factory()->create(['role' => Role::Docente, 'valid_from' => '2026-08-01']);
        $ana = adminSeat(User::factory()->create(), '2026-08-01');

        expect($this->rules->endBlocker($docente))->toContain('ficha del docente')
            ->and($this->rules->endBlocker($ana))->toContain('único nombramiento de Administración');

        adminSeat(User::factory()->create(), '2026-12-01');

        expect($this->rules->endBlocker($ana))->toBeNull();

        adminSeat(User::factory()->create(), '2026-01-01');

        expect($this->rules->endBlocker($ana))->toBeNull();
    });
});

describe('deactivationGap (AZ-S12, P3)', function () {
    beforeEach(function () {
        $this->travelTo('2026-11-03 10:00:00');
        $this->rules = app(RoleAssignmentRules::class);
    });

    it('da el primer día sin admin vigente si se desactiva al usuario', function (array $seats, array $others, ?string $expected) {
        $user = User::factory()->create();

        foreach ($seats as $seat) {
            adminSeat($user, $seat[0], $seat[1] ?? null);
        }

        foreach ($others as $other) {
            adminSeat(User::factory()->create(), $other[0], $other[1] ?? null);
        }

        expect($this->rules->deactivationGap($user)?->toDateString())->toBe($expected);
    })->with([
        'sin Administración' => [[], [['2026-08-01', '2026-11-30']], null],
        'Administración programada y Ana hasta el 30 de noviembre' => [[['2026-12-01']], [['2026-08-01', '2026-11-30']], '2026-12-01'],
        'lo mismo, con Beto sin fin' => [[['2026-12-01']], [['2026-08-01', '2026-11-30'], ['2026-01-01']], null],
        'dos programados: no cuenta el otro del mismo usuario' => [[['2026-12-01'], ['2027-02-01']], [['2026-08-01', '2026-11-30']], '2026-12-01'],
    ]);
});

it('rolesForUnit: un departamento académico admite jefe y jefe de proyecto docente; Subdirección Académica, la Subdirección; las demás unidades, ninguno', function () {
    $rules = app(RoleAssignmentRules::class);
    $academic = OrgUnit::find(app(OrgTree::class)->idOf(OrgTree::ACADEMIC));
    $planning = OrgUnit::factory()->create(['kind' => OrgUnitKind::Subdireccion]);
    $department = OrgUnit::factory()->create(['kind' => OrgUnitKind::DepartamentoAcademico]);
    $office = OrgUnit::factory()->create(['kind' => OrgUnitKind::Oficina]);

    expect($rules->rolesForUnit($department))->toBe([Role::JefeDepartamento, Role::JefeProyectoDocente])
        ->and($rules->rolesForUnit($academic))->toBe([Role::Subdireccion])
        ->and($rules->rolesForUnit($planning))->toBe([])
        ->and($rules->rolesForUnit($office))->toBe([])
        ->and($rules->rolesForUnit(OrgUnit::where('code', 'DIRECCION')->sole()))->toBe([]);
});
