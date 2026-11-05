<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\UpdateOrgUnit;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\OrgUnitUpdated;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| UpdateOrgUnit cambia el nombre, la clave o el tipo de una unidad estructural (spec §6.1). AZ-S13 (confirmado el
| 3 de noviembre de 2026): los departamentos académicos se editan en Académico y la Dirección y la Subdirección
| Académica conservan su clave.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->unit = OrgUnit::factory()->create(['code' => 'PLAN', 'name' => 'Planeación', 'kind' => OrgUnitKind::Departamento]);
    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function updateUnitInvalid(Closure $call, string $key, string $message): void
{
    expect($call)->toThrow(function (ValidationException $exception) use ($key, $message): void {
        expect($exception->errors()[$key] ?? null)->toBe([$message]);
    });
}

it('cambia nombre, clave y tipo; la bitácora guarda solo lo que cambió', function () {
    Event::fake([OrgUnitUpdated::class]);

    $unit = app(UpdateOrgUnit::class)->handle($this->unit, ['code' => 'PLAN-2', 'name' => 'Planeación', 'kind' => OrgUnitKind::Oficina]);

    expect($unit->fresh())->code->toBe('PLAN-2')->name->toBe('Planeación')->kind->toBe(OrgUnitKind::Oficina);

    $entry = activitySince()->where('event', 'updated')->sole();

    expect($entry->description)->toBe('Editó la unidad PLAN-2')
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->attribute_changes['attributes'])->toBe(['code' => 'PLAN-2', 'kind' => 'oficina'])
        ->and($entry->attribute_changes['old'])->toBe(['code' => 'PLAN', 'kind' => 'departamento']);

    Event::assertDispatched(OrgUnitUpdated::class, fn (OrgUnitUpdated $event): bool => $event->unit->is($unit));
});

it('sin cambios no escribe ni registra', function () {
    Event::fake([OrgUnitUpdated::class]);

    app(UpdateOrgUnit::class)->handle($this->unit, ['code' => 'PLAN', 'name' => 'Planeación', 'kind' => OrgUnitKind::Departamento]);

    expect(activitySince()->count())->toBe(0);
    Event::assertNotDispatched(OrgUnitUpdated::class);
});

it('AZ-S13: un departamento académico no se edita aquí', function () {
    $department = OrgUnit::find(Department::factory()->create(['name' => 'Sistemas'])->id);

    updateUnitInvalid(
        fn () => app(UpdateOrgUnit::class)->handle($department, ['name' => 'Otro']),
        'unit',
        'Es un departamento académico: su nombre, su clave y su estado se editan en Académico › Departamentos.',
    );

    expect($department->fresh()->name)->toBe('Sistemas');
});

it('AZ-S13: la clave de DIRECCION y SUB-ACADEMICA no cambia, su nombre sí', function () {
    $root = OrgUnit::where('code', 'DIRECCION')->sole();
    $academic = OrgUnit::where('code', 'SUB-ACADEMICA')->sole();

    updateUnitInvalid(fn () => app(UpdateOrgUnit::class)->handle($root, ['code' => 'DIR']), 'code', 'Nexus usa la clave DIRECCION; no se cambia.');
    updateUnitInvalid(fn () => app(UpdateOrgUnit::class)->handle($academic, ['code' => 'ACAD']), 'code', 'Nexus usa la clave SUB-ACADEMICA; no se cambia.');

    app(UpdateOrgUnit::class)->handle($academic, ['code' => 'SUB-ACADEMICA', 'name' => 'Subdirección Académica del ITCJ']);

    expect($academic->fresh()->name)->toBe('Subdirección Académica del ITCJ');
});

it('AZ-S16: la clave de Servicios Escolares no cambia mientras tenga jefe vigente o por empezar', function () {
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento]);
    $head = RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::JefeDepartamento, 'org_unit_id' => $services->id, 'valid_from' => today()->addWeek()]);

    updateUnitInvalid(
        fn () => app(UpdateOrgUnit::class)->handle($services, ['code' => 'ESCOLARES']),
        'code',
        'Nexus usa la clave SERVICIOS-ESCOLARES para el jefe de Servicios Escolares; termina su nombramiento antes de cambiarla.',
    );

    app(UpdateOrgUnit::class)->handle($services, ['name' => 'Departamento de Servicios Escolares del ITCJ']);
    $head->update(['valid_from' => today()->subMonth(), 'valid_to' => today()->subDay()]);
    app(UpdateOrgUnit::class)->handle($services->fresh(), ['code' => 'ESCOLARES']);

    expect($services->fresh())->code->toBe('ESCOLARES')->name->toBe('Departamento de Servicios Escolares del ITCJ');
});

it('AZ-S16: Servicios Escolares con jefe no cambia a otro tipo, y el error nombra al jefe por su unidad', function () {
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento]);
    RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::JefeDepartamento, 'org_unit_id' => $services->id]);

    updateUnitInvalid(
        fn () => app(UpdateOrgUnit::class)->handle($services, ['kind' => OrgUnitKind::Oficina]),
        'kind',
        'Tiene nombramientos de Jefe del Departamento de Servicios Escolares; ese puesto pide una unidad de tipo Departamento.',
    );

    expect($services->fresh()->kind)->toBe(OrgUnitKind::Departamento);
});

it('la clave nueva no puede ser de otra unidad', function () {
    OrgUnit::factory()->create(['code' => 'OTRA']);

    updateUnitInvalid(fn () => app(UpdateOrgUnit::class)->handle($this->unit, ['code' => 'OTRA']), 'code', 'Ya hay una unidad con la clave OTRA.');
});

it('AZ-S13: Subdirección Académica no cambia de tipo, porque Nexus la usa como subdirección', function () {
    $academic = OrgUnit::where('code', 'SUB-ACADEMICA')->sole();

    updateUnitInvalid(
        fn () => app(UpdateOrgUnit::class)->handle($academic, ['kind' => OrgUnitKind::Division]),
        'kind',
        'Nexus usa Subdirección Académica como subdirección; no cambia de tipo.',
    );

    expect($academic->fresh()->kind)->toBe(OrgUnitKind::Subdireccion)
        ->and(activitySince()->count())->toBe(0);
});

it('no cambia a un tipo que deja sin lugar sus nombramientos', function () {
    $legacy = OrgUnit::factory()->create(['code' => 'SUB-OTRA', 'name' => 'Otra subdirección', 'kind' => OrgUnitKind::Subdireccion]);
    $holder = User::factory()->create();
    RoleAssignment::factory()->create(['user_id' => $holder->id, 'role' => Role::Subdireccion, 'org_unit_id' => $legacy->id]);

    updateUnitInvalid(
        fn () => app(UpdateOrgUnit::class)->handle($legacy, ['kind' => OrgUnitKind::Division]),
        'kind',
        'Tiene nombramientos de Subdirección Académica; ese puesto pide una unidad de tipo Subdirección.',
    );

    $root = OrgUnit::where('code', 'DIRECCION')->sole();
    updateUnitInvalid(fn () => app(UpdateOrgUnit::class)->handle($root, ['kind' => OrgUnitKind::Oficina]), 'kind', 'La Dirección no cambia de tipo.');
    updateUnitInvalid(fn () => app(UpdateOrgUnit::class)->handle($this->unit, ['kind' => OrgUnitKind::Direccion]), 'kind', 'Solo hay una Dirección.');
    updateUnitInvalid(fn () => app(UpdateOrgUnit::class)->handle($this->unit, ['kind' => OrgUnitKind::DepartamentoAcademico]), 'kind', 'Los departamentos académicos se dan de alta en Académico.');
});
