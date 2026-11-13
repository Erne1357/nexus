<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\SetOrgUnitActive;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\OrgUnitDeactivating;
use App\Modules\Core\Events\OrgUnitUpdated;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SetOrgUnitActive desactiva y activa unidades estructurales (spec §6.1). AZ-S13 (confirmado el 3 de noviembre de
| 2026): la Dirección no se desactiva y los departamentos académicos se desactivan en Administración › Departamentos.
*/

beforeEach(function () {
    $this->travelTo('2026-11-03 10:00:00');
    $this->admin = User::factory()->admin()->create();
    $this->parent = OrgUnit::factory()->create(['code' => 'PADRE', 'name' => 'Madre']);
    $this->leaf = OrgUnit::factory()->create(['parent_id' => $this->parent->id, 'code' => 'HOJA', 'name' => 'Hoja']);
    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function activeInvalid(Closure $call, string $key, array $messages): void
{
    expect($call)->toThrow(function (ValidationException $exception) use ($key, $messages): void {
        expect($exception->errors()[$key] ?? null)->toBe($messages);
    });
}

it('desactiva una hoja y la vuelve a activar, con bitácora y evento', function () {
    Event::fake([OrgUnitUpdated::class]);

    app(SetOrgUnitActive::class)->handle($this->leaf, false);

    expect($this->leaf->fresh()->is_active)->toBeFalse();
    $entry = activitySince()->where('event', 'updated')->sole();
    expect($entry->description)->toBe('Desactivó la unidad HOJA')
        ->and($entry->attribute_changes['attributes'])->toBe(['is_active' => false])
        ->and($entry->attribute_changes['old'])->toBe(['is_active' => true]);

    app(SetOrgUnitActive::class)->handle($this->leaf, true);

    expect($this->leaf->fresh()->is_active)->toBeTrue()
        ->and(activitySince()->latest('id')->first()->description)->toBe('Activó la unidad HOJA');
    Event::assertDispatched(OrgUnitUpdated::class, 2);
});

it('no desactiva con hijas activas', function () {
    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($this->parent, false), 'unit', ['Tiene 1 unidad hija activa; muévela o desactívala primero.']);

    OrgUnit::factory()->create(['parent_id' => $this->parent->id]);
    OrgUnit::factory()->inactive()->create(['parent_id' => $this->parent->id]);

    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($this->parent, false), 'unit', ['Tiene 2 unidades hijas activas; muévelas o desactívalas primero.']);

    expect($this->parent->fresh()->is_active)->toBeTrue();
});

it('no desactiva con nombramientos vigentes o por empezar; los terminados no cuentan', function () {
    $academic = OrgUnit::where('code', 'SUB-ACADEMICA')->sole();
    $sub = OrgUnit::factory()->create(['code' => 'SUB-X', 'name' => 'Sub X', 'kind' => OrgUnitKind::Subdireccion]);
    RoleAssignment::factory()->create(['role' => Role::Subdireccion, 'org_unit_id' => $sub->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);

    app(SetOrgUnitActive::class)->handle($sub, false);
    expect($sub->fresh()->is_active)->toBeFalse();

    app(SetOrgUnitActive::class)->handle($sub, true);
    RoleAssignment::factory()->create(['role' => Role::Subdireccion, 'org_unit_id' => $sub->id, 'valid_from' => '2026-07-01', 'valid_to' => null]);

    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($sub, false), 'unit', ['Tiene 1 nombramiento vigente o por empezar; termínalo en Personas y puestos.']);

    RoleAssignment::factory()->create(['role' => Role::Docente, 'org_unit_id' => $sub->id, 'valid_from' => '2027-01-01', 'valid_to' => null]);

    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($sub, false), 'unit', ['Tiene 2 nombramientos vigentes o por empezar; termínalos en Personas y puestos.']);
    expect($academic->fresh()->is_active)->toBeTrue();
});

it('AZ-S13: la Dirección no se desactiva; un departamento académico se desactiva en Administración › Departamentos', function () {
    $root = OrgUnit::where('code', 'DIRECCION')->sole();
    $department = OrgUnit::find(Department::factory()->create(['name' => 'Sistemas'])->id);

    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($root, false), 'unit', [
        'La Dirección es la raíz: no se desactiva.',
        'Tiene 2 unidades hijas activas; muévelas o desactívalas primero.',
    ]);
    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($department, false), 'unit', [
        'Es un departamento académico: su nombre, su clave y su estado se editan en Administración › Departamentos.',
    ]);
});

it('AZ-S13: Subdirección Académica no se desactiva, porque Nexus la usa', function () {
    $academic = OrgUnit::where('code', 'SUB-ACADEMICA')->sole();

    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($academic, false), 'unit', ['Nexus usa la Subdirección Académica: no se desactiva.']);

    expect($academic->fresh()->is_active)->toBeTrue()
        ->and(activitySince()->count())->toBe(0);
});

it('al activar bloquea la madre, para que una desactivación al mismo tiempo no la deje inactiva con una hija activa', function () {
    app(SetOrgUnitActive::class)->handle($this->leaf, false);
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
    });

    app(SetOrgUnitActive::class)->handle($this->leaf, true);

    expect(collect($statements)->contains(fn (array $query): bool => str_contains($query['sql'], 'for update') && in_array($this->parent->id, $query['bindings'], true)))->toBeTrue()
        ->and($this->leaf->fresh()->is_active)->toBeTrue();
});

it('no activa una unidad cuya madre está inactiva', function () {
    app(SetOrgUnitActive::class)->handle($this->leaf, false);
    app(SetOrgUnitActive::class)->handle($this->parent, false);

    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($this->leaf, true), 'unit', ['Activa primero Madre.']);
    expect($this->leaf->fresh()->is_active)->toBeFalse();
});

it('un módulo puede vetar la desactivación', function () {
    Event::listen(OrgUnitDeactivating::class, fn (OrgUnitDeactivating $event) => $event->block('Tiene salones'));

    activeInvalid(fn () => app(SetOrgUnitActive::class)->handle($this->leaf, false), 'unit', ['Tiene salones']);

    expect($this->leaf->fresh()->is_active)->toBeTrue()
        ->and(activitySince()->count())->toBe(0);
});

it('sin cambio no escribe', function () {
    Event::fake([OrgUnitUpdated::class]);

    app(SetOrgUnitActive::class)->handle($this->leaf, true);

    expect(activitySince()->count())->toBe(0);
    Event::assertNotDispatched(OrgUnitUpdated::class);
});
