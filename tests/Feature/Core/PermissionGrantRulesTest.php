<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Students\Enums\StudentsPermission;

/*
| CF-03 y CF-04 sobre los permisos reales: qué es candado y qué concesiones de fábrica se bloquearían si no fueran de
| fábrica (CF-04 las deja valer porque se revisaron por PR). Si la lista crece, una concesión nueva de fábrica amplía
| el alcance que el puesto lleva en su nombramiento: revísala con el usuario.
*/

it('CF-03: los candados son usuarios, roles y permisos, nombramientos, organigrama, bitácora, correos y colas, el interruptor de la carga académica (MN-05), y editar o borrar lo que da el alcance', function () {
    $registry = app(PermissionRegistry::class);
    $locked = ['user', 'authz', 'assignment', 'activity', 'mail', 'queue', 'org_unit', 'academic_load', 'access'];
    $expected = collect(CorePermission::cases())
        ->filter(fn (CorePermission $permission): bool => in_array(explode('.', $permission->value)[1], $locked, true))
        ->map(fn (CorePermission $permission): string => $permission->value)
        ->push('core.campus.update', 'core.modality.update', 'academic.career.delete', 'academic.department.delete')
        ->sort()->values()->all();

    expect($registry->adminOnly())->toBe($expected)
        ->and($registry->adminOnly())->toContain('core.authz.manage', 'core.org_unit.view', 'core.user.create', 'core.activity.view')
        ->and(array_filter($registry->adminOnly(), fn (string $key): bool => preg_match('/^core\.(login_notice|login_shortcut|setting)\./', $key) === 1))->toBe([])
        ->and($registry->isAdminOnly(CorePermission::ModalityUpdateProfile))->toBeFalse()
        ->and($registry->grants(Role::Subdireccion, CorePermission::ModalityUpdateProfile))->toBeTrue();

    // La única excepción de fábrica (AZ-03, confirmado el 20 de noviembre de 2026): el directivo ve Personas y puestos,
    // el Organigrama y la Bitácora.
    $exceptions = ['core.activity.view', 'core.assignment.view', 'core.org_unit.view'];

    foreach ($registry->permissions() as $permission) {
        if ($registry->isAdminOnly($permission)) {
            expect($registry->rolesGranting($permission))->toBe(
                in_array($permission->value, $exceptions, true) ? [Role::Directivo] : [],
                "{$permission->value} es candado y se concede de fábrica",
            );
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

    // CF-04 «departamento o carrera» (confirmado el 14 de noviembre de 2026): las once que declaran unidad y carrera a la
    // vez ya pasan sin la excepción. La propuesta declara la carrera sin la unidad, y la Subdirección acota la unidad:
    // esa sigue valiendo solo por ser de fábrica.
    // Lo mismo pasa con ver las corridas del motor de una carrera (tarea 6.2): Subdirección las ve todas, de fábrica.
    expect($blocked)->toBe(['subdireccion → academic_load.proposal.view', 'subdireccion → solver.career_run.view']);
});

it('CF-04: con unidad y carrera basta acotar una; lo que declara solo la unidad sigue bloqueado para el coordinador', function () {
    $rules = app(PermissionGrantRules::class);
    $passing = [
        [Role::Subdireccion, StudentsPermission::StudentView],
        [Role::JefeDepartamento, StudentsPermission::StudentView],
        [Role::Coordinador, StudentsPermission::StudentView],
        [Role::Subdireccion, SchedulingPermission::ScheduleView],
        [Role::JefeDepartamento, SchedulingPermission::ScheduleView],
        [Role::JefeProyectoDocente, SchedulingPermission::ScheduleView],
        [Role::Coordinador, SchedulingPermission::ScheduleView],
        [Role::JefeDepartamento, SchedulingPermission::GroupManage],
        [Role::Coordinador, SchedulingPermission::GroupManage],
        [Role::JefeDepartamento, SchedulingPermission::SessionEdit],
        [Role::Coordinador, SchedulingPermission::SessionEdit],
    ];

    foreach ($passing as [$role, $permission]) {
        expect($rules->scopeProblem($role, $permission))->toBeNull("{$role->value} → {$permission->value}");
    }

    expect($rules->grantProblem(Role::Coordinador, AcademicLoadPermission::LoadClose))
        ->toBe('«Cerrar la carga de un departamento» no se da a Coordinador de carrera: el puesto no lleva departamento y el permiso valdría en todos los departamentos.')
        ->and($rules->grantProblem(Role::JefeProyectoDocente, StudentsPermission::StudentView))->toBeNull()
        ->and($rules->grantProblem(Role::JefeDepartamento, AcademicLoadPermission::ProposalView))
        ->toBe('«Ver la propuesta de una carrera» no se da a Jefe de departamento: el puesto no lleva carrera y el permiso valdría en todas las carreras.');
});

it('lo que vale en todo el Tec se puede dar con aviso: abrir el periodo y ver modalidades, para el jefe y la Subdirección', function (Role $role) {
    $rules = app(PermissionGrantRules::class);

    foreach ([CorePermission::TermOpen, CorePermission::ModalityView, CorePermission::TermCreate] as $permission) {
        expect($rules->grantProblem($role, $permission))->toBeNull()
            ->and($rules->isInstitutionWide($role, $permission))->toBeTrue();
    }

    expect($rules->isInstitutionWide(Role::Coordinador, CorePermission::TermOpen))->toBeFalse()
        ->and($rules->isInstitutionWide(Role::Coordinador, CorePermission::TermCreate))->toBeTrue()
        ->and($rules->isInstitutionWide($role, StudentsPermission::StudentView))->toBeFalse();
})->with([Role::JefeDepartamento, Role::Subdireccion]);

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
