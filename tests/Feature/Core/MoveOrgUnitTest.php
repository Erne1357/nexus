<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\MoveOrgUnit;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Events\OrgUnitMoved;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| MoveOrgUnit cambia la madre de una unidad con sus hijas, sin ciclos (spec §6.1). AZ-S13 (confirmado el 3 de
| noviembre de 2026): los departamentos académicos siguen bajo la Subdirección Académica.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->root = OrgUnit::where('code', 'DIRECCION')->sole();
    $this->planning = OrgUnit::factory()->create(['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación y Vinculación', 'kind' => OrgUnitKind::Subdireccion]);
    $this->services = OrgUnit::factory()->create(['parent_id' => $this->planning->id, 'code' => 'SERVICIOS-ESCOLARES', 'name' => 'Servicios Escolares', 'sort' => 2]);
    $this->office = OrgUnit::factory()->create(['parent_id' => $this->services->id, 'code' => 'OF-1', 'name' => 'Oficina 1']);
    $this->admin2 = OrgUnit::factory()->create(['code' => 'SUB-ADMIN', 'name' => 'Subdirección de Servicios Administrativos', 'kind' => OrgUnitKind::Subdireccion]);
    $this->actingAs($this->admin);
    $this->activityBase = (int) Activity::max('id');
});

function moveInvalid(Closure $call, string $key, string $message): void
{
    expect($call)->toThrow(function (ValidationException $exception) use ($key, $message): void {
        expect($exception->errors()[$key] ?? null)->toBe([$message]);
    });
}

it('mueve una unidad con sus hijas, al final de sus nuevas hermanas, con bitácora', function () {
    Event::fake([OrgUnitMoved::class]);
    OrgUnit::factory()->create(['parent_id' => $this->admin2->id, 'sort' => 7]);

    $unit = app(MoveOrgUnit::class)->handle($this->services, $this->admin2);

    expect($unit->fresh())->parent_id->toBe($this->admin2->id)->sort->toBe(8)
        ->and($this->office->fresh()->parent_id)->toBe($this->services->id)
        ->and((new OrgTree(app('cache')->store()))->covers($this->admin2->id, $this->office->id))->toBeTrue()
        ->and((new OrgTree(app('cache')->store()))->covers($this->planning->id, $this->office->id))->toBeFalse();

    $entry = activitySince()->where('event', 'updated')->sole();

    expect($entry->description)->toBe('Movió la unidad SERVICIOS-ESCOLARES de SUB-PLANEACION a SUB-ADMIN')
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->attribute_changes['attributes'])->toBe(['parent_id' => $this->admin2->id])
        ->and($entry->attribute_changes['old'])->toBe(['parent_id' => $this->planning->id])
        ->and($entry->properties['from'])->toBe('SUB-PLANEACION')
        ->and($entry->properties['to'])->toBe('SUB-ADMIN');

    Event::assertDispatched(OrgUnitMoved::class, fn (OrgUnitMoved $event): bool => $event->unit->is($unit));
});

it('AZ-S16: la Subdirección Académica no se mueve, así ningún jefe la tiene debajo de su unidad', function () {
    $academic = OrgUnit::where('code', OrgTree::ACADEMIC)->sole();

    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($academic, $this->services), 'unit', 'Nexus usa la Subdirección Académica: no se mueve.');

    expect($academic->fresh()->parent_id)->toBe($this->root->id)
        ->and(activitySince()->count())->toBe(0);
});

it('rechaza ciclos', function () {
    $message = 'Subdirección de Planeación y Vinculación no puede depender de sí misma ni de una de sus hijas.';

    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($this->planning, $this->services), 'parent', $message);
    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($this->planning, $this->office), 'parent', $message);
    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($this->planning, $this->planning), 'parent', $message);

    expect($this->planning->fresh()->parent_id)->toBe($this->root->id);
});

it('si el árbol cambia entre la revisión y la escritura, el trigger lo rechaza con el mismo mensaje', function () {
    OrgUnit::updating(function (): void {
        DB::table('org_units')->where('id', $this->admin2->id)->update(['parent_id' => $this->planning->id]);
    });

    moveInvalid(
        fn () => app(MoveOrgUnit::class)->handle($this->planning, $this->admin2),
        'parent',
        'Subdirección de Planeación y Vinculación no puede depender de sí misma ni de una de sus hijas.',
    );

    expect($this->planning->fresh()->parent_id)->toBe($this->root->id)
        ->and($this->admin2->fresh()->parent_id)->toBe($this->root->id);
});

it('AZ-S13: la Dirección y los departamentos académicos no se mueven aquí', function () {
    $department = OrgUnit::find(Department::factory()->create(['name' => 'Sistemas'])->id);

    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($this->root, $this->planning), 'unit', 'La Dirección es la raíz: no se mueve.');
    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($department, $this->planning), 'unit', 'Un departamento académico no se mueve desde el Organigrama.');
});

it('rechaza una madre inactiva o un departamento académico como madre', function () {
    $department = OrgUnit::find(Department::factory()->create(['name' => 'Sistemas'])->id);
    $inactive = OrgUnit::factory()->inactive()->create(['name' => 'Oficina Z']);

    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($this->office, $inactive), 'parent', 'Oficina Z está inactiva.');
    moveInvalid(fn () => app(MoveOrgUnit::class)->handle($this->office, $department), 'parent', 'Un departamento académico no tiene unidades hijas en el Organigrama.');
});

it('a la misma madre no escribe', function () {
    Event::fake([OrgUnitMoved::class]);

    app(MoveOrgUnit::class)->handle($this->services, $this->planning);

    expect($this->services->fresh()->sort)->toBe(2)
        ->and(activitySince()->count())->toBe(0);
    Event::assertNotDispatched(OrgUnitMoved::class);
});
