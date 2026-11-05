<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\OrgUnitsTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;

/*
| Plantilla `org_units`: unidades del organigrama por clave. Nunca crea ni toca una unidad de departamento
| académico (eso es del alta del departamento, ADR-0018) y el padre debe existir. Una unidad existente solo se
| mueve o cambia de tipo si el Organigrama lo permite (OrgUnitRules, AZ-S13 y AZ-S16), con el mismo mensaje.
*/

beforeEach(function () {
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new OrgUnitsTemplate;
    $this->department = Department::factory()->create(['code' => 'DEP1']);
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function orgUnitRow(array $changes = []): array
{
    return (new OrgUnitsTemplate)->normalize(['code' => 'cs-1', 'name' => 'Coordinación 1', 'kind' => 'oficina', 'parent' => 'DEP1', 'active' => 'No', ...$changes]);
}

it('está registrada en Imports', function () {
    expect(app(TemplateRegistry::class)->get('org_units'))->toBeInstanceOf(OrgUnitsTemplate::class);
});

it('da de alta bajo su padre y respeta el estado inactivo de un alta', function () {
    $unit = $this->template->apply(orgUnitRow(), $this->context);

    expect($unit->fresh())->code->toBe('CS-1')->kind->value->toBe('oficina')->is_active->toBeFalse()
        ->and($unit->parent_id)->toBe($this->department->id);
});

it('no reactiva ni cambia el estado de una unidad existente', function () {
    $this->template->apply(orgUnitRow(), $this->context);

    $issues = $this->template->validate(orgUnitRow(['active' => 'Sí']), $this->context);
    $this->template->apply(orgUnitRow(['active' => 'Sí', 'name' => 'Nuevo nombre']), $this->context);

    expect($issues->hasErrors())->toBeFalse()
        ->and(OrgUnit::query()->where('code', 'CS-1')->sole())->name->toBe('Nuevo nombre')->is_active->toBeFalse();
});

it('rechaza un padre que no existe', function () {
    expect($this->template->validate(orgUnitRow(['parent' => 'NOEXISTE']), $this->context)->errors)->toHaveKey('parent');
});

it('rechaza el tipo departamento académico y una clave que ya es de un departamento', function () {
    expect($this->template->validate(orgUnitRow(['kind' => 'departamento_academico']), $this->context)->errors)->toHaveKey('kind')
        ->and($this->template->validate(orgUnitRow(['code' => 'dep1']), $this->context)->errors)->toHaveKey('code');
});

it('rechaza un tipo que Nexus no conoce y los campos vacíos', function () {
    expect($this->template->validate(orgUnitRow(['kind' => 'otra']), $this->context)->errors)->toHaveKey('kind')
        ->and($this->template->validate(orgUnitRow(['name' => null, 'parent' => null]), $this->context)->errors)->toHaveKeys(['name', 'parent']);
});

it('rechaza mover una unidad bajo su propio descendiente', function () {
    $this->template->apply(orgUnitRow(), $this->context);
    $this->template->apply(orgUnitRow(['code' => 'CS-2', 'parent' => 'CS-1']), $this->context);

    expect($this->template->validate(orgUnitRow(['code' => 'CS-1', 'parent' => 'CS-2']), $this->context)->errors)->toHaveKey('parent');
});

it('AZ-S16: no mueve la Subdirección Académica ni la Dirección, con el mensaje del Organigrama', function (string $code, string $message) {
    $unit = OrgUnit::query()->where('code', $code)->sole();
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Servicios Escolares', 'kind' => OrgUnitKind::Departamento]);

    $issues = $this->template->validate(orgUnitRow(['code' => $code, 'name' => $unit->name, 'kind' => $unit->kind->value, 'parent' => $services->code]), $this->context);

    expect($issues->errors)->toBe(['parent' => [$message]])
        ->and($unit->fresh()->parent_id)->not->toBe($services->id);
})->with([
    'Subdirección Académica' => [OrgTree::ACADEMIC, 'Nexus usa la Subdirección Académica: no se mueve.'],
    'Dirección' => [OrgTree::ROOT, 'La Dirección es la raíz: no se mueve.'],
]);

it('AZ-S13: no cambia el tipo de la Subdirección Académica, con el mensaje del Organigrama', function () {
    $academic = OrgUnit::query()->where('code', OrgTree::ACADEMIC)->sole();
    $parent = OrgUnit::query()->find($academic->parent_id);

    $issues = $this->template->validate(orgUnitRow(['code' => OrgTree::ACADEMIC, 'name' => $academic->name, 'kind' => 'division', 'parent' => $parent->code]), $this->context);

    expect($issues->errors)->toBe(['kind' => ['Nexus usa Subdirección Académica como subdirección; no cambia de tipo.']]);
});

it('AZ-S16: no cambia el tipo de Servicios Escolares mientras su jefe lo necesite', function () {
    $services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento]);
    RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::JefeDepartamento, 'org_unit_id' => $services->id]);
    $parent = OrgUnit::query()->find($services->parent_id);

    $issues = $this->template->validate(orgUnitRow(['code' => $services->code, 'name' => $services->name, 'kind' => 'oficina', 'parent' => $parent->code]), $this->context);

    expect($issues->errors)->toBe(['kind' => ['Tiene nombramientos de Jefe del Departamento de Servicios Escolares; ese puesto pide una unidad de tipo Departamento.']]);
});

it('no da de alta una segunda Dirección', function () {
    expect($this->template->validate(orgUnitRow(['kind' => 'direccion']), $this->context)->errors)->toBe(['kind' => ['Solo hay una Dirección.']]);
});

it('una unidad común sí se mueve y cambia de tipo', function () {
    $this->template->apply(orgUnitRow(), $this->context);
    $other = OrgUnit::factory()->create(['code' => 'OTRA', 'name' => 'Otra oficina', 'kind' => OrgUnitKind::Departamento]);
    $row = orgUnitRow(['parent' => 'OTRA', 'kind' => 'departamento']);

    $issues = $this->template->validate($row, $this->context);
    $this->template->apply($row, $this->context);

    expect($issues->hasErrors())->toBeFalse()
        ->and(OrgUnit::query()->where('code', 'CS-1')->sole())->parent_id->toBe($other->id)->kind->toBe(OrgUnitKind::Departamento);
});

it('no ofrece desactivar faltantes', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});
