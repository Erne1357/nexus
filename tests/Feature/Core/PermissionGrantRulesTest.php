<?php

use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;

/*
| CF-03 y CF-04 sobre los permisos reales: qué es candado y qué concesiones de fábrica se bloquearían si no fueran de
| fábrica (CF-04 las deja valer porque se revisaron por PR). La segunda lista se revisa con el usuario: si crece, una
| concesión nueva de fábrica amplía el alcance que el puesto lleva en su nombramiento.
*/

it('CF-03: los candados son los de usuarios, roles y permisos, nombramientos, organigrama, bitácora, correos y colas', function () {
    $registry = app(PermissionRegistry::class);
    $locked = ['user', 'authz', 'assignment', 'activity', 'mail', 'queue', 'org_unit'];
    $expected = collect(CorePermission::cases())
        ->filter(fn (CorePermission $permission): bool => in_array(explode('.', $permission->value)[1], $locked, true))
        ->map(fn (CorePermission $permission): string => $permission->value)
        ->sort()->values()->all();

    expect($registry->adminOnly())->toBe($expected)
        ->and($registry->adminOnly())->toContain('core.authz.manage', 'core.org_unit.view', 'core.user.create', 'core.activity.view')
        ->and(array_filter($registry->adminOnly(), fn (string $key): bool => preg_match('/^core\.(login_notice|login_shortcut|setting)\./', $key) === 1))->toBe([]);

    foreach ($registry->permissions() as $permission) {
        if ($registry->isAdminOnly($permission)) {
            expect($registry->rolesGranting($permission))->toBe([], "{$permission->value} es candado y se concede de fábrica");
        }
    }
});

it('CF-04: las concesiones de fábrica que se bloquearían sin la excepción de fábrica', function () {
    $registry = app(PermissionRegistry::class);
    $rules = app(PermissionGrantRules::class);
    $blocked = [];

    foreach ($registry->permissions() as $permission) {
        foreach ($registry->rolesGranting($permission) as $role) {
            if ($rules->scopeProblem($role, $permission) !== null) {
                $blocked[] = "{$role->value} → {$permission->value}";
            }

            expect($rules->grantProblem($role, $permission))->toBeNull();
        }
    }

    // El coordinador acota la carrera y no la unidad; el jefe, la Subdirección y el jefe de proyecto docente, la unidad y
    // no la carrera. Estos permisos declaran la carrera (y casi todos también la unidad), así que cada puesto deja
    // abierta la que no lleva. CF-04 pide acotar cada dimensión: darlos a otro puesto se bloquea (supuesto por
    // confirmar; ver la adenda del 14 de noviembre de 2026 a ADR-0018).
    expect($blocked)->toBe([
        'subdireccion → students.student.view',
        'jefe_departamento → students.student.view',
        'coordinador → students.student.view',
        'subdireccion → academic_load.proposal.view',
        'subdireccion → scheduling.schedule.view',
        'jefe_departamento → scheduling.schedule.view',
        'jefe_proyecto_docente → scheduling.schedule.view',
        'coordinador → scheduling.schedule.view',
        'jefe_departamento → scheduling.group.manage',
        'coordinador → scheduling.group.manage',
        'jefe_departamento → scheduling.session.edit',
        'coordinador → scheduling.session.edit',
    ]);
});

it('CF-04: ningún puesto sin alcance tiene de fábrica un permiso de gestión', function () {
    $registry = app(PermissionRegistry::class);

    foreach ([Role::Docente, Role::Alumno] as $role) {
        $management = array_filter(
            $registry->permissions(),
            fn (Permission $permission): bool => $registry->grants($role, $permission) && ! $permission->isPersonal(),
        );

        expect($role->boundedDimensions())->toBe([])
            ->and(array_values(array_map(fn (Permission $permission): string => $permission->value, $management)))->toBe([]);
    }
});
