<?php

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Rules\RoleAssignmentRules;

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

it('managedElsewhere dice dónde se maneja el puesto que no es de esta pantalla (AZ-S11)', function () {
    $rules = app(RoleAssignmentRules::class);

    expect($rules->managedElsewhere(Role::Docente))->toBe('El puesto de docente lo da la ficha del docente: se activa y se desactiva con el docente.')
        ->and($rules->managedElsewhere(Role::JefeDepartamento))->toBe('Las jefaturas se nombran y se terminan en la ficha del docente.')
        ->and($rules->managedElsewhere(Role::JefeProyectoDocente))->toBe('Las jefaturas se nombran y se terminan en la ficha del docente.')
        ->and($rules->managedElsewhere(Role::Admin))->toBeNull()
        ->and($rules->managedElsewhere(Role::Subdireccion))->toBeNull()
        ->and($rules->managedElsewhere(Role::Coordinador))->toBeNull();
});
