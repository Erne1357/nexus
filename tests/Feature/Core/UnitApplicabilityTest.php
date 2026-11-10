<?php

use App\Modules\Academic\Enums\AcademicPermission;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Authorization\UnitApplicability;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\FacultyPermission;
use App\Modules\Students\Enums\StudentsPermission;
use Illuminate\Support\Facades\Cache;

/*
| AZ-02 (D20, confirmado el 5 de noviembre de 2026): lo que puede un jefe depende de SU unidad. AZ-S16: un permiso declara a qué
| unidades aplica (UnitApplicability: tipos y claves); un nombramiento con unidad aporta el permiso solo si su unidad, o
| una de sus descendientes, es de las que declara. Lo académico aplica a los departamentos académicos y a la
| Subdirección Académica; `academic.placement.validate`, a Servicios Escolares. Un nombramiento sin unidad (admin,
| coordinador, docente) y un permiso sin aplicabilidad se comportan como antes.
*/

beforeEach(function () {
    $this->academic = OrgUnit::query()->where('code', OrgTree::ACADEMIC)->sole();
    $this->services = OrgUnit::factory()->create(['code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento, 'parent_id' => $this->academic->id]);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->systems->id]);

    $this->servicesHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->services->id])->create();
    $this->academicHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->systems->id])->create();
});

/**
 * Las claves de los permisos de gestión que `$user` puede sobre algo (allowsAny), ordenadas.
 *
 * @return list<string>
 */
function effectivePermissions(User $user): array
{
    test()->actingAs($user);
    $actor = app(Actor::class);

    $keys = collect(app(PermissionRegistry::class)->permissions())
        ->reject(fn (Permission $permission): bool => $permission->isPersonal())
        ->filter(fn (Permission $permission): bool => $actor->allowsAny($permission))
        ->map(fn (Permission $permission): string => (string) $permission->value)
        ->values()
        ->all();
    sort($keys);

    return $keys;
}

/**
 * De unas claves, las de permisos de gestión (los personales no se miden aquí).
 *
 * @param  list<string>  $keys
 * @return list<string>
 */
function managementKeys(array $keys): array
{
    $personal = collect(app(PermissionRegistry::class)->permissions())
        ->filter(fn (Permission $permission): bool => $permission->isPersonal())
        ->map(fn (Permission $permission): string => (string) $permission->value)
        ->all();

    return array_values(array_diff($keys, $personal));
}

describe('AZ-02: lo que cada puesto ejerce', function () {
    it('AZ-02: el jefe de Servicios Escolares solo ejerce academic.placement.validate y los exportes del personal de lo publicado', function () {
        expect(effectivePermissions($this->servicesHead))->toBe(['academic.placement.validate', 'academic_load.load.export']);
    });

    it('AZ-S16: el jefe de un departamento académico conserva todo lo de su puesto salvo academic.placement.validate, que nunca ejercía', function () {
        $granted = array_values(array_diff(app(PermissionRegistry::class)->matrix()[Role::JefeDepartamento->value], ['academic.placement.validate']));

        expect(effectivePermissions($this->academicHead))->toBe($granted);
    });

    it('AZ-S16: la Subdirección, el jefe de proyecto docente, el coordinador y el docente ejercen lo que la matriz les concede', function (Role $role, Closure $scope) {
        $user = User::factory()->withRole($role, $scope($this))->create();

        expect(effectivePermissions($user))->toBe(managementKeys(app(PermissionRegistry::class)->matrix()[$role->value] ?? []));
    })->with([
        'subdirección' => [Role::Subdireccion, fn (): array => []],
        'jefe de proyecto docente' => [Role::JefeProyectoDocente, fn ($test): array => ['campus_id' => $test->c2->id, 'department_id' => $test->systems->id]],
        'coordinador' => [Role::Coordinador, fn ($test): array => ['campus_id' => $test->c2->id, 'career_id' => $test->isc->id]],
        'docente' => [Role::Docente, fn (): array => []],
    ]);

    it('AZ-S16: el admin ejerce todo menos sus excepciones', function () {
        $registry = app(PermissionRegistry::class);
        $all = collect($registry->permissions())->map(fn (Permission $permission): string => (string) $permission->value)
            ->reject(fn (string $key): bool => in_array($key, $registry->adminDenials(), true))->sort()->values()->all();
        $all = managementKeys($all);

        expect(effectivePermissions(User::factory()->admin()->create()))->toBe($all);
    });
});

describe('AZ-02: allows(), constrain() y rolesAllowing()', function () {
    it('AZ-02: el jefe de Servicios Escolares no cubre lo académico ni sobre su propia unidad, y sí valida sobre ella', function () {
        $actor = app(Actor::class)->forUser($this->servicesHead);

        expect($actor->allows(FacultyPermission::TeacherView, Target::of(unit: $this->services->id)))->toBeFalse()
            ->and($actor->allows(StudentsPermission::StudentView, Target::of(campus: $this->c2->id, unit: $this->services->id, career: $this->isc->id)))->toBeFalse()
            ->and($actor->rolesAllowing(FacultyPermission::TeacherView, Target::of(unit: $this->services->id)))->toBe([])
            ->and($actor->allows(AcademicPermission::PlacementValidate, Target::of(unit: $this->services->id)))->toBeTrue()
            ->and($actor->rolesAllowing(AcademicPermission::PlacementValidate, Target::of(unit: $this->services->id)))->toBe([Role::JefeDepartamento]);
    });

    it('AZ-02: el jefe de un departamento académico no valida pendientes', function () {
        $actor = app(Actor::class)->forUser($this->academicHead);

        expect($actor->allowsAny(AcademicPermission::PlacementValidate))->toBeFalse()
            ->and($actor->allows(AcademicPermission::PlacementValidate, Target::of(unit: $this->systems->id)))->toBeFalse()
            ->and($actor->allows(FacultyPermission::TeacherView, Target::of(unit: $this->systems->id)))->toBeTrue();
    });

    it('AZ-S16: quien es jefe de un departamento académico y de Servicios Escolares ejerce cada permiso solo con el nombramiento cuya unidad cubre', function () {
        vacate(Role::JefeDepartamento, $this->services->id);
        RoleAssignment::factory()->create(['user_id' => $this->academicHead->id, 'role' => Role::JefeDepartamento, 'org_unit_id' => $this->services->id, 'valid_from' => today()->toDateString()]);
        $this->actingAs($this->academicHead->fresh());
        $actor = app(Actor::class);

        $teachersUnits = $actor->constrain(OrgUnit::query(), FacultyPermission::TeacherView, ['unit' => 'id'])->pluck('id')->all();
        $placementUnits = $actor->constrain(OrgUnit::query(), AcademicPermission::PlacementValidate, ['unit' => 'id'])->pluck('id')->all();

        expect($teachersUnits)->toBe([$this->systems->id])
            ->and($placementUnits)->toBe([$this->services->id])
            ->and($actor->allows(FacultyPermission::TeacherView, Target::of(unit: $this->services->id)))->toBeFalse()
            ->and($actor->allows(FacultyPermission::TeacherView, Target::of(unit: $this->systems->id)))->toBeTrue()
            ->and($actor->allows(AcademicPermission::PlacementValidate, Target::of(unit: $this->systems->id)))->toBeFalse()
            ->and($actor->allows(AcademicPermission::PlacementValidate, Target::of(unit: $this->services->id)))->toBeTrue();
    });
});

describe('AZ-S16: OrgTree::fits()', function () {
    beforeEach(function () {
        Cache::flush();
        app()->forgetScopedInstances();
    });

    it('lo académico aplica a un departamento académico y a la Subdirección Académica, no a Servicios Escolares', function () {
        $tree = app(OrgTree::class);

        expect($tree->fits($this->systems->id, UnitApplicability::academic()))->toBeTrue()
            ->and($tree->fits($this->academic->id, UnitApplicability::academic()))->toBeTrue()
            ->and($tree->fits($this->services->id, UnitApplicability::academic()))->toBeFalse()
            ->and($tree->fits($this->services->id, UnitApplicability::of(codes: [OrgTree::SCHOOL_SERVICES])))->toBeTrue()
            ->and($tree->fits($this->systems->id, UnitApplicability::of(codes: [OrgTree::SCHOOL_SERVICES])))->toBeFalse()
            ->and($tree->fits('no-existe', UnitApplicability::academic()))->toBeFalse();
    });

    it('una unidad cumple si ella o una de sus descendientes es de las que declara, nunca por un ancestro', function () {
        $office = OrgUnit::factory()->create(['code' => 'OF-CONTROL', 'kind' => OrgUnitKind::Oficina, 'parent_id' => $this->services->id]);
        $tree = app(OrgTree::class);

        expect($tree->fits($this->services->id, UnitApplicability::of(codes: ['OF-CONTROL'])))->toBeTrue()
            ->and($tree->fits($this->services->id, UnitApplicability::of([OrgUnitKind::Oficina])))->toBeTrue()
            ->and($tree->fits($office->id, UnitApplicability::of(codes: [OrgTree::SCHOOL_SERVICES])))->toBeFalse();
    });

    it('la clave solo cuenta en unidades estructurales: un departamento académico que la repite no cumple', function () {
        $clash = Department::factory()->create(['code' => 'OF-CLAVE']);
        OrgUnit::factory()->create(['code' => 'OF-CLAVE', 'kind' => OrgUnitKind::Oficina]);

        expect(app(OrgTree::class)->fits($clash->id, UnitApplicability::of(codes: ['OF-CLAVE'])))->toBeFalse();
    });

    it('un cambio de clave o de tipo cuenta desde la siguiente petición', function () {
        $unit = OrgUnit::factory()->create(['code' => 'X-ANTES', 'kind' => OrgUnitKind::Departamento]);
        expect(app(OrgTree::class)->fits($unit->id, UnitApplicability::of(codes: ['X-ANTES'])))->toBeTrue();

        $unit->update(['code' => 'X-DESPUES', 'kind' => OrgUnitKind::Oficina]);
        app()->forgetScopedInstances();
        $tree = app(OrgTree::class);

        expect($tree->fits($unit->id, UnitApplicability::of(codes: ['X-ANTES'])))->toBeFalse()
            ->and($tree->fits($unit->id, UnitApplicability::of(codes: ['X-DESPUES'])))->toBeTrue()
            ->and($tree->fits($unit->id, UnitApplicability::of([OrgUnitKind::Oficina])))->toBeTrue()
            ->and($tree->fits($unit->id, UnitApplicability::of([OrgUnitKind::Departamento])))->toBeFalse();
    });

    it('UnitApplicability pide al menos un tipo o una clave, y ninguna clave vacía', function () {
        $academic = UnitApplicability::academic();

        expect($academic->matches(OrgUnitKind::Subdireccion, OrgTree::ACADEMIC))->toBeTrue('la Subdirección Académica cumple por su clave, aunque no tenga departamentos debajo')
            ->and($academic->matches(OrgUnitKind::DepartamentoAcademico, 'CUALQUIERA'))->toBeTrue()
            ->and($academic->matches(OrgUnitKind::Subdireccion, 'SUB-PLANEACION'))->toBeFalse()
            ->and($academic->matches(OrgUnitKind::DepartamentoAcademico, OrgTree::ACADEMIC))->toBeTrue()
            ->and(UnitApplicability::of(codes: [OrgTree::ACADEMIC])->matches(OrgUnitKind::DepartamentoAcademico, OrgTree::ACADEMIC))->toBeFalse('un departamento académico que repite la clave no cumple por clave');

        expect(fn () => UnitApplicability::of())->toThrow(LogicException::class)
            ->and(fn () => UnitApplicability::of(codes: ['']))->toThrow(LogicException::class)
            ->and(UnitApplicability::academic()->key())->toBe('kinds=departamento_academico;codes=SUB-ACADEMICA')
            ->and(UnitApplicability::of(codes: ['B', 'A'])->key())->toBe('codes=A,B');
    });
});
