<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\CreateOrgUnit;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Events\OrgUnitCreated;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CreateOrgUnit da de alta unidades estructurales bajo una madre (spec §6.1, AZ-S13): los departamentos académicos
| se dan de alta en Administración › Departamentos y la Dirección es la raíz única.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->root = OrgUnit::query()->where('code', 'DIRECCION')->sole();
    $this->parent = OrgUnit::factory()->create(['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación', 'kind' => OrgUnitKind::Subdireccion, 'sort' => 3]);
    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function createUnit(OrgUnit $parent, string $code = 'PLANEACION', string $name = 'Planeación', OrgUnitKind $kind = OrgUnitKind::Departamento): OrgUnit
{
    return app(CreateOrgUnit::class)->handle($parent, ['code' => $code, 'name' => $name, 'kind' => $kind]);
}

function expectUnitInvalid(Closure $call, string $key, string $message): void
{
    expect($call)->toThrow(function (ValidationException $exception) use ($key, $message): void {
        expect($exception->errors()[$key] ?? null)->toBe([$message]);
    });
}

it('da de alta una unidad bajo otra, al final de sus hermanas, con bitácora y evento después del commit', function () {
    Event::fake([OrgUnitCreated::class]);
    OrgUnit::factory()->create(['parent_id' => $this->parent->id, 'sort' => 4]);

    $unit = createUnit($this->parent);

    expect($unit->fresh())
        ->parent_id->toBe($this->parent->id)
        ->code->toBe('PLANEACION')
        ->name->toBe('Planeación')
        ->kind->toBe(OrgUnitKind::Departamento)
        ->sort->toBe(5)
        ->is_active->toBeTrue();

    $entry = activitySince()->where('event', 'created')->sole();

    expect($entry->subject->is($unit))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->description)->toBe('Dio de alta la unidad PLANEACION · Planeación');

    Event::assertDispatched(OrgUnitCreated::class, fn (OrgUnitCreated $event): bool => $event->unit->is($unit));
});

it('rechaza los tipos que no se crean aquí', function (OrgUnitKind $kind, string $message) {
    expectUnitInvalid(fn () => createUnit($this->parent, kind: $kind), 'kind', $message);

    expect(OrgUnit::where('code', 'PLANEACION')->exists())->toBeFalse();
})->with([
    'dirección' => [OrgUnitKind::Direccion, 'Solo hay una Dirección.'],
    'departamento académico' => [OrgUnitKind::DepartamentoAcademico, 'Los departamentos académicos se dan de alta en Administración › Departamentos.'],
]);

it('rechaza una madre inactiva, borrada o que es departamento académico', function () {
    $inactive = OrgUnit::factory()->create(['name' => 'Oficina X']);
    $deleted = OrgUnit::factory()->create(['name' => 'Oficina Y']);
    $academic = OrgUnit::find(Department::factory()->create(['name' => 'Sistemas'])->id);

    $staleInactive = OrgUnit::find($inactive->id);
    $staleDeleted = OrgUnit::find($deleted->id);
    DB::table('org_units')->where('id', $inactive->id)->update(['is_active' => false]);
    DB::table('org_units')->where('id', $deleted->id)->update(['deleted_at' => now()]);

    expectUnitInvalid(fn () => createUnit($staleInactive), 'parent', 'Oficina X está inactiva.');
    expectUnitInvalid(fn () => createUnit($staleDeleted), 'parent', 'Oficina Y está inactiva.');
    expectUnitInvalid(fn () => createUnit($academic), 'parent', 'Un departamento académico no tiene unidades hijas en el Organigrama.');
});

it('la clave es única entre las unidades estructurales, también las borradas; la de un departamento académico sí se puede repetir', function () {
    $gone = OrgUnit::factory()->create(['code' => 'VIEJA']);
    $gone->delete();

    expectUnitInvalid(fn () => createUnit($this->parent, 'VIEJA'), 'code', 'Ya hay una unidad con la clave VIEJA.');
    expectUnitInvalid(fn () => createUnit($this->parent, 'SUB-PLANEACION'), 'code', 'Ya hay una unidad con la clave SUB-PLANEACION.');

    Department::factory()->create(['code' => 'SIS']);

    expect(createUnit($this->parent, 'SIS')->fresh()->code)->toBe('SIS');
});

it('si otra sesión toma la clave entre la revisión y la escritura, el error es el mismo', function () {
    OrgUnit::creating(function (): void {
        DB::table('org_units')->insert([
            'id' => (string) Str::uuid7(), 'parent_id' => $this->root->id, 'code' => 'CARRERA', 'name' => 'Otra', 'kind' => 'oficina',
            'sort' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    expectUnitInvalid(fn () => createUnit($this->parent, 'CARRERA'), 'code', 'Ya hay una unidad con la clave CARRERA.');
});
