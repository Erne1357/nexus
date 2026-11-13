<?php

use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use App\Modules\Facilities\Enums\FacilitiesPermission;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Imports\Enums\ImportsPermission;
use App\Modules\Scheduling\Enums\SchedulingPermission;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Students\Enums\StudentsPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Authorization\FakePermission;
use Tests\Support\Authorization\CampusBoundWrites;

/*
| Spec §7.1 (Autorización por permisos): un nombramiento con una dimensión vacía cubre cualquier valor, así que un
| permiso sobre un recurso con `campus_id` que no declara `Dimension::Campus` se concedería "en todos los campus"
| aunque el puesto sea de uno. Esta prueba lo impide (§14) y fija D4 a D6 del plan 3.P9 y la excepción RV (revisiones sin campus):
| D4: `core.term.open` declaraba campus y desde M4 declara modalidad (`term_openings` ya no tiene campus); D5:
| `core.assignment.*` e `imports.batch.*`, solo del admin; D6: `catalog_validation.progress.*`, solo de puestos de todo
| el Tec; RV: `catalog_validation.review.*` sin campus ni modalidad (`core.term.manage_calendar` dejó de ser excepción: `term_stages` ya no tiene campus, M4).
| AZ-S17 (MS6): solo los permisos de modalityLevelPermissions() se acotan por la modalidad en lugar del campus; uno nuevo
| sobre algo con campus declara el campus. Las tablas con `modality_id` piden la dimensión modalidad, salvo D5 y D6
| (`core.term.manage_calendar`: `term_stages.modality_id` nulo es el calendario general).
*/

/**
 * Recurso (`modulo.recurso`) o clave completa de un permiso -> tabla que lo respalda.
 *
 * Nota: `academic.career.update` también escribe `career_campus` (careers.php, toggleOffering, vía SetCareerOffering),
 * con el campus que elige la pantalla. Hoy es solo del admin; el día que se conceda a otro puesto, esa oferta por
 * campus necesita su propio permiso con la dimensión campus.
 *
 * @return array<string, string>
 */
function dimensionTables(): array
{
    return [
        'core.campus' => 'campuses',
        'core.modality' => 'modalities',
        'core.term' => 'terms',
        'core.term.open' => 'term_openings',
        'core.term.manage_calendar' => 'term_stages',
        'core.assignment' => 'role_assignments',
        'core.org_unit' => 'org_units',
        'core.user' => 'users',
        // Ver en el menú lo no disponible depende del periodo activo (MN-02), no de un recurso con campus.
        'core.navigation' => 'terms',
        'academic.department' => 'departments',
        'academic.career' => 'careers',
        'academic.subject' => 'subjects',
        'academic.study_plan' => 'study_plans',
        'academic.placement' => 'study_plan_subject_pendings',
        'facilities.building' => 'buildings',
        'facilities.room' => 'rooms',
        'faculty.teacher' => 'teachers',
        'faculty.teacher_qualification' => 'teacher_subject_qualifications',
        'faculty.teacher_department' => 'teacher_departments',
        'faculty.teacher_user' => 'users',
        'imports.batch' => 'import_batches',
        'students.student' => 'students',
        'catalog_validation.review' => 'catalog_reviews',
        'catalog_validation.progress' => 'catalog_reviews',
        'academic_load.load' => 'academic_loads',
        'academic_load.proposal' => 'career_proposals',
        'academic_load.proposal_review' => 'proposal_reviews',
        'academic_load.agreement' => 'interdepartmental_agreements',
        'scheduling.schedule' => 'groups',
        'scheduling.group' => 'groups',
        'scheduling.session' => 'group_sessions',
        'scheduling.room' => 'group_sessions',
        'scheduling.quota' => 'group_quotas',
        'staffing.ranking' => 'staffing_rankings',
        'staffing.teacher' => 'group_teacher_assignments',
        'staffing.teacher_load' => 'teacher_term_loads',
        'staffing.change_request' => 'change_requests',
    ];
}

/**
 * Permisos que, sobre una tabla con `campus_id`, pueden no declarar campus, con su motivo (D5, D6 y RV).
 *
 * @return array<string, string>
 */
function dimensionExceptions(): array
{
    $admin = 'D5: solo del admin, que cubre todo; si se concede a otro puesto hay que decidir sus dimensiones en ese PR.';
    $noCampus = 'RV: las revisiones de catálogos son del departamento en todo el Tec, sin campus ni modalidad (AZ-S17, supuesto por confirmar); `catalog_reviews.campus_id` siempre es nulo (CHECK) y, si el permiso declarara el campus, un nombramiento con campus nunca cubriría una revisión sin campus.';
    $tec = 'D6: solo de puestos de todo el Tec (hoy la Subdirección); el panel o tablero abarca todos los campus.';

    return [
        'core.assignment.view' => $admin,
        'core.assignment.appoint' => $admin,
        'core.assignment.end' => $admin,
        'imports.batch.view' => $admin,
        'imports.batch.create' => $admin,
        'imports.batch.apply' => $admin,
        'catalog_validation.progress.view' => $tec,
        'catalog_validation.progress.remind' => $tec,
        'catalog_validation.review.open' => $admin,
        'catalog_validation.review.view' => $noCampus,
        'catalog_validation.review.resolve_flag' => $noCampus,
        'catalog_validation.review.correct' => $noCampus,
        'catalog_validation.review.validate' => $noCampus,
        'catalog_validation.review.reopen' => $noCampus,
        'catalog_validation.review.flag' => $noCampus,
    ];
}

/**
 * Los puestos que no se acotan por campus ni por dimensión en los permisos de gestión: el admin cubre todo y la
 * Subdirección es de todo el Tec. Se escribe a mano: derivarla de "no tiene campus_id" exentaría al jefe y al docente.
 *
 * @return list<Role>
 */
function dimensionWholeTecRoles(): array
{
    return [Role::Subdireccion];
}

function dimensionTableFor(Permission $permission): ?string
{
    $parts = explode('.', $permission->value);

    return dimensionTables()[$permission->value] ?? dimensionTables()[$parts[0].'.'.$parts[1]] ?? null;
}

function dimensionHasCampus(Permission $permission): bool
{
    return in_array(Dimension::Campus, $permission->dimensions(), true);
}

/**
 * AZ-S17 (MS6): los permisos que, sobre una tabla con `campus_id`, se acotan por la modalidad en lugar del campus, con
 * su motivo. Lista explícita: un permiso nuevo sobre algo con campus declara el campus salvo que se agregue aquí.
 *
 * @return array<string, string>
 */
function modalityLevelPermissions(): array
{
    return [
        'scheduling.schedule.view' => 'MS6: los horarios se leen a nivel de modalidad; el campus es un filtro de la pantalla.',
        'academic_load.agreement.view' => 'MS6: los acuerdos se leen a nivel de modalidad, del departamento que pide o del que decide.',
        'academic_load.agreement.decide' => 'M12: el jefe decide los acuerdos de toda la modalidad de su departamento, de cualquier campus.',
    ];
}

/**
 * Si el permiso se acota por la modalidad en lugar del campus (modalityLevelPermissions): declara la modalidad y su
 * tabla la tiene. Lo que escribe algo con campus (CampusBoundWrites) nunca está en la lista.
 */
function dimensionBoundsByModality(Permission $permission, string $table): bool
{
    return isset(modalityLevelPermissions()[$permission->value])
        && in_array(Dimension::Modality, $permission->dimensions(), true)
        && Schema::hasColumn($table, 'modality_id');
}

/**
 * Permisos que, sobre una tabla con `modality_id`, pueden no declarar la modalidad, con su motivo.
 *
 * @return array<string, string>
 */
function modalityDimensionExceptions(): array
{
    $admin = 'D5: solo del admin, que cubre todo; si se concede a otro puesto hay que decidir sus dimensiones en ese PR.';
    $tec = 'D6: solo de puestos de todo el Tec (hoy la Subdirección), que cubren todas las modalidades; `term_stages.modality_id` nulo es el calendario general del periodo, que no es de ninguna modalidad (M4).';

    return [
        'core.assignment.view' => $admin,
        'core.assignment.appoint' => $admin,
        'core.assignment.end' => $admin,
        'core.term.manage_calendar' => $tec,
    ];
}

/**
 * Concesiones (puesto, permiso) cuyo permiso no declara ninguna dimensión que el puesto lleve en su nombramiento:
 * ahí el nombramiento tendría esa dimensión vacía y cubriría cualquier valor (§7.1).
 *
 * @return list<string>
 */
function dimensionUncoveredGrants(PermissionRegistry $registry): array
{
    $found = [];

    foreach ($registry->permissions() as $permission) {
        if ($permission->isPersonal()) {
            continue;
        }

        foreach ($registry->rolesGranting($permission) as $role) {
            if (in_array($role, dimensionWholeTecRoles(), true)) {
                continue;
            }

            $declared = array_map(fn (Dimension $dimension): string => $dimension->column(), $permission->dimensions());

            if (array_intersect($declared, $role->scopeColumns()) === []) {
                $found[] = "{$role->value} → {$permission->value}";
            }
        }
    }

    return $found;
}

$enums = [
    CorePermission::class,
    AcademicPermission::class,
    FacilitiesPermission::class,
    FacultyPermission::class,
    StudentsPermission::class,
    ImportsPermission::class,
    CatalogValidationPermission::class,
    AcademicLoadPermission::class,
    SchedulingPermission::class,
    StaffingPermission::class,
];

it('la prueba revisa los enums de permisos de todos los módulos', function () use ($enums) {
    $classes = array_values(array_unique(array_map(
        fn (Permission $permission): string => $permission::class,
        app(PermissionRegistry::class)->permissions(),
    )));
    sort($classes);
    $expected = $enums;
    sort($expected);

    expect($classes)->toBe($expected);
});

it('spec §7.1: ningún permiso del módulo sobre un recurso con campus_id omite la dimensión campus', function (string $enum) {
    $problems = [];

    foreach ($enum::cases() as $permission) {
        if ($permission->isPersonal()) {
            continue;
        }

        $table = dimensionTableFor($permission);

        if ($table === null || ! Schema::hasTable($table)) {
            $problems[] = "{$permission->value}: sin tabla en el mapa o la tabla no existe";

            continue;
        }

        if (Schema::hasColumn($table, 'campus_id') && ! dimensionHasCampus($permission) && ! dimensionBoundsByModality($permission, $table) && ! isset(dimensionExceptions()[$permission->value])) {
            $problems[] = "{$permission->value}: {$table}.campus_id sin la dimensión campus";
        }
    }

    expect($problems)->toBe([]);
})->with($enums);

it('AZ-S17: ningún permiso del módulo sobre un recurso con modality_id omite la dimensión modalidad', function (string $enum) {
    $problems = [];

    foreach ($enum::cases() as $permission) {
        $table = dimensionTableFor($permission);

        if ($permission->isPersonal() || $table === null || ! Schema::hasTable($table)) {
            continue;
        }

        if (Schema::hasColumn($table, 'modality_id') && ! in_array(Dimension::Modality, $permission->dimensions(), true) && ! isset(modalityDimensionExceptions()[$permission->value])) {
            $problems[] = "{$permission->value}: {$table}.modality_id sin la dimensión modalidad";
        }
    }

    expect($problems)->toBe([]);
})->with($enums);

it('AZ-S17: cada excepción de modalidad es solo del admin (D5) o solo de puestos de todo el Tec (D6), y sigue siendo necesaria', function (string $key, string $reason) {
    $registry = app(PermissionRegistry::class);
    $permission = collect($registry->permissions())->firstWhere('value', $key);

    expect($permission)->not->toBeNull()
        ->and(Schema::hasColumn(dimensionTableFor($permission), 'modality_id'))->toBeTrue()
        ->and($permission->dimensions())->not->toContain(Dimension::Modality);

    foreach ($registry->rolesGranting($permission) as $role) {
        expect(str_starts_with($reason, 'D6') && in_array($role, dimensionWholeTecRoles(), true))->toBeTrue("{$role->value} tiene {$key}");
    }
})->with(fn (): array => collect(modalityDimensionExceptions())->map(fn (string $reason, string $key): array => [$key, $reason])->all());

it('cada excepción es solo del admin (D5), solo de puestos de todo el Tec (D6) o de revisiones sin campus (RV)', function (string $key, string $reason) {
    $registry = app(PermissionRegistry::class);
    $permission = collect($registry->permissions())->firstWhere('value', $key);

    expect($permission)->not->toBeNull();

    if (str_starts_with($reason, 'D5')) {
        expect($registry->rolesGranting($permission))->toBe([], "{$key} es solo del admin");

        return;
    }

    if (str_starts_with($reason, 'RV')) {
        $check = DB::selectOne("select pg_get_constraintdef(oid) as definition from pg_constraint where conname = 'catalog_reviews_campus_check'");

        expect($check?->definition)->toContain('campus_id IS NULL')
            ->and($permission->dimensions())->not->toContain(Dimension::Campus)->not->toContain(Dimension::Modality)
            ->and(array_values(array_filter($registry->rolesGranting($permission), fn (Role $role): bool => in_array($role, [Role::JefeProyectoDocente, Role::Coordinador], true))))->toBe([], "{$key} no se concede a un puesto que lleva campus");

        return;
    }

    foreach ($registry->rolesGranting($permission) as $role) {
        expect(in_array($role, dimensionWholeTecRoles(), true))->toBeTrue("{$role->value} tiene {$key}, una excepción de todo el Tec");
    }
})->with(fn (): array => collect(dimensionExceptions())->map(fn (string $reason, string $key): array => [$key, $reason])->all());

it('AZ-S17: cada permiso acotado por la modalidad sigue siéndolo: su tabla tiene campus, no declara el campus y no escribe algo con campus', function (string $key) {
    $permission = collect(app(PermissionRegistry::class)->permissions())->firstWhere('value', $key);

    expect($permission)->not->toBeNull()
        ->and(Schema::hasColumn(dimensionTableFor($permission), 'campus_id'))->toBeTrue()
        ->and(dimensionHasCampus($permission))->toBeFalse()
        ->and($permission->dimensions())->toContain(Dimension::Modality)
        ->and(in_array($permission, CampusBoundWrites::all(), true))->toBeFalse();
})->with(array_keys(modalityLevelPermissions()));

it('cada excepción sigue siendo necesaria', function (string $key) {
    $permission = collect(app(PermissionRegistry::class)->permissions())->firstWhere('value', $key);

    expect(Schema::hasColumn(dimensionTableFor($permission), 'campus_id'))->toBeTrue()
        ->and(dimensionHasCampus($permission))->toBeFalse();
})->with(array_keys(dimensionExceptions()));

it('todo permiso concedido declara una dimensión que el puesto lleva en su nombramiento', function () {
    expect(dimensionUncoveredGrants(app(PermissionRegistry::class)))->toBe([]);
});

it('la guardia de dimensiones detecta una concesión que dejaría una dimensión vacía', function () {
    $registry = PermissionRegistry::fromDeclarations(
        [CorePermission::class],
        [Role::Coordinador->value => [CorePermission::OrgUnitView], Role::Subdireccion->value => [CorePermission::OrgUnitView]],
        [],
    );

    expect(dimensionUncoveredGrants($registry))->toBe(['coordinador → core.org_unit.view']);
});

it('la guardia de dimensiones es solo de permisos de gestión: uno personal concedido al docente no se señala', function () {
    $personal = PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Docente->value => [FakePermission::ViewOwn]], []);
    $management = PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Docente->value => [FakePermission::ViewAny]], []);

    expect(dimensionUncoveredGrants($personal))->toBe([])
        ->and(dimensionUncoveredGrants($management))->toBe(['docente → fake.thing.view_any']);
});
