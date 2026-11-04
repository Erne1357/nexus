<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\EndRoleAssignment;
use App\Modules\Core\Enums\AssignmentStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PersonRecordQuery;
use Carbon\CarbonImmutable;

/*
| La ficha de una persona en «Personas y puestos»: sus nombramientos ordenados, las cifras, la línea de vigencias y la
| bitácora de sus puestos, con quién terminó cada uno.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');

    $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    CareerCampus::factory()->create(['career_id' => $this->isc->id, 'campus_id' => $this->c2->id]);
    $this->systems = Department::factory()->create(['name' => 'Sistemas y Computación']);

    $this->actingAs($this->admin);
});

function giveRole(User $user, Role $role, string $from, ?string $to = null, array $scope = []): RoleAssignment
{
    return RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => $role, 'valid_from' => $from, 'valid_to' => $to, ...$scope]);
}

it('ordena vigentes, programados y terminados, y calcula las cifras', function () {
    $docente = giveRole($this->rosa, Role::Docente, '2015-08-17');
    $coordinador = giveRole($this->rosa, Role::Coordinador, '2026-08-01', '2027-07-31', ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);
    $futuro = giveRole($this->rosa, Role::JefeProyectoDocente, '2027-02-01', null, ['campus_id' => $this->c2->id, 'org_unit_id' => $this->systems->id]);
    $viejo = giveRole($this->rosa, Role::Coordinador, '2023-01-01', '2024-06-30', ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);
    $masViejo = giveRole($this->rosa, Role::Coordinador, '2020-01-01', '2021-06-30', ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);

    $record = app(PersonRecordQuery::class)->for($this->rosa);

    expect(array_map(fn (array $row): string => $row['assignment']->id, $record->assignments))
        ->toBe([$coordinador->id, $docente->id, $futuro->id, $viejo->id, $masViejo->id])
        ->and(array_map(fn (array $row): AssignmentStatus => $row['status'], $record->assignments))
        ->toBe([AssignmentStatus::Current, AssignmentStatus::Current, AssignmentStatus::Scheduled, AssignmentStatus::Ended, AssignmentStatus::Ended])
        ->and($record->assignments[0]['label']->summary)->toBe('Coordinador de carrera · ISC · C2')
        ->and($record->assignments[0]['daysLeft'])->toBe(270)
        ->and($record->assignments[1]['daysLeft'])->toBeNull()
        ->and($record->current)->toBe(2)
        ->and($record->past)->toBe(2)
        ->and($record->since->toDateString())->toBe('2015-08-17')
        ->and($record->nextExpiry['on']->toDateString())->toBe('2027-07-31')
        ->and($record->nextExpiry['summary'])->toBe('Coordinador de carrera · ISC · C2')
        ->and($record->timeline->hasRange())->toBeTrue()
        ->and($record->timeline->rows)->toHaveCount(5)
        ->and($record->initials())->toBe('RR');
});

it('una persona sin nombramientos no tiene cifras', function () {
    $record = app(PersonRecordQuery::class)->for($this->rosa);

    expect($record->assignments)->toBe([])
        ->and($record->current)->toBe(0)
        ->and($record->since)->toBeNull()
        ->and($record->nextExpiry)->toBeNull()
        ->and($record->lastAppointment)->toBeNull()
        ->and($record->log)->toBe([])
        ->and($record->timeline->hasRange())->toBeFalse();
});

it('sabe quién terminó un nombramiento por su entrada de bitácora', function () {
    $assignment = app(AppointRole::class)->handle($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id, CarbonImmutable::parse('2026-11-03'));
    $this->travelTo('2026-11-10 10:00:00');
    app(EndRoleAssignment::class)->handle($assignment, CarbonImmutable::parse('2026-11-12'));
    $this->travelTo('2026-11-20 10:00:00');

    $row = app(PersonRecordQuery::class)->for($this->rosa)->assignments[0];

    expect($row['endedBy']['name'])->toBe('Ada Admin')
        ->and($row['endedBy']['on']->toDateString())->toBe('2026-11-10');

    $record = app(PersonRecordQuery::class)->for($this->rosa);

    expect($record->lastAppointment['by'])->toBe('Ada Admin')
        ->and($record->lastAppointment['on']->toDateString())->toBe('2026-11-03')
        ->and(array_column($record->log, 'description'))->toBe([
            'Terminó el nombramiento de Rosa Ruiz como Coordinador de carrera · ISC · C2',
            'Nombró a Rosa Ruiz como Coordinador de carrera · ISC · C2',
        ]);
});

it('AZ-S11 y AZ-S12: el docente, las jefaturas y el último admin vigente no se terminan aquí', function () {
    giveRole($this->rosa, Role::Docente, '2015-08-17');
    giveRole($this->rosa, Role::JefeDepartamento, '2026-01-01', null, ['org_unit_id' => $this->systems->id]);
    giveRole($this->rosa, Role::JefeProyectoDocente, '2026-01-01', null, ['campus_id' => $this->c2->id, 'org_unit_id' => $this->systems->id]);
    giveRole($this->rosa, Role::Coordinador, '2026-01-01', null, ['campus_id' => $this->c2->id, 'career_id' => $this->isc->id]);
    giveRole($this->rosa, Role::Admin, '2026-01-01');

    $blockers = collect(app(PersonRecordQuery::class)->for($this->rosa)->assignments)
        ->mapWithKeys(fn (array $row): array => [$row['assignment']->role->value => $row['endBlocker']]);

    expect($blockers['docente'])->toContain('ficha del docente')
        ->and($blockers['jefe_departamento'])->toContain('ficha del docente')
        ->and($blockers['jefe_proyecto_docente'])->toContain('ficha del docente')
        ->and($blockers['coordinador'])->toBeNull()
        ->and($blockers['admin'])->toBeNull();

    $own = fn () => collect(app(PersonRecordQuery::class)->for($this->admin)->assignments)->firstWhere('assignment.role', Role::Admin);

    expect($own()['endBlocker'])->toBeNull();

    $this->rosa->roleAssignments()->where('role', Role::Admin)->delete();

    expect($own()['endBlocker'])->toContain('único nombramiento de Administración');
});

it('la bitácora solo trae entradas de nombramientos', function () {
    app(AppointRole::class)->handle($this->rosa, Role::Coordinador, $this->c2, null, $this->isc->id, CarbonImmutable::parse('2026-11-03'));
    activity()->performedOn($this->rosa)->event('updated')->withProperties(['switch' => true])->log('Cambió de perfil');

    $log = app(PersonRecordQuery::class)->for($this->rosa)->log;

    expect(array_column($log, 'description'))->toBe(['Nombró a Rosa Ruiz como Coordinador de carrera · ISC · C2']);
});

it('la bitácora trae las 10 últimas', function () {
    foreach (range(1, 12) as $number) {
        activity()->performedOn($this->rosa)->event('updated')->withProperties(['role_assignment_id' => fakeKey($number)])->log("Entrada {$number}");
    }

    $log = app(PersonRecordQuery::class)->for($this->rosa)->log;

    expect($log)->toHaveCount(10)->and($log[0]['description'])->toBe('Entrada 12');
});
