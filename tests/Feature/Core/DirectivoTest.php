<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Actions\GrantPositionPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\GrantTable;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Students\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| R-V2-4 (confirmado el 19 de noviembre de 2026), AZ-03: «directivos ven todo». El puesto `directivo` se nombra sobre la
| Dirección o una subdirección que no sea la Académica, un titular por unidad; su credencial nombra la unidad y lee
| todo lo académico del Tec (todas las unidades, carreras, campus y modalidades activos) sin escribir nada. La unidad no
| acota lo que lee: el directivo de Planeación y Vinculación, cuyo subárbol no tiene nada académico, lo ve todo.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00', 'America/Ciudad_Juarez'));
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-25', 'ends_on' => '2027-05-28']);

    $root = app(OrgTree::class)->idOf(OrgTree::ROOT);
    $this->direction = OrgUnit::query()->findOrFail($root);
    $this->academic = OrgUnit::query()->findOrFail(app(OrgTree::class)->idOf(OrgTree::ACADEMIC));
    $this->planning = OrgUnit::factory()->create(['code' => 'SUB-PLANEACION', 'name' => 'Subdirección de Planeación y Vinculación', 'kind' => OrgUnitKind::Subdireccion, 'parent_id' => $root]);
    $this->administrative = OrgUnit::factory()->create(['code' => 'SUB-ADMINISTRATIVA', 'name' => 'Subdirección de Servicios Administrativos', 'kind' => OrgUnitKind::Subdireccion, 'parent_id' => $root]);

    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->basics = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->systems->id]);
    $this->ige = Career::factory()->create(['code' => 'IGE', 'department_id' => $this->basics->id]);

    $this->directivo = User::factory()->withRole(Role::Directivo, ['org_unit_id' => $this->planning->id])->create(['name' => 'Diana Directiva']);
});

/**
 * La carga, una retícula, un docente y un alumno de cada departamento, el alumno de Sistemas en C1 y el de Ciencias
 * Básicas en C2.
 *
 * @return array{loads: list<AcademicLoad>, plans: list<StudyPlan>, teachers: list<Teacher>, students: list<Student>}
 */
function directivoWorld(object $test): array
{
    $world = ['loads' => [], 'plans' => [], 'teachers' => [], 'students' => []];

    foreach ([[$test->systems, $test->isc, $test->c1, 'Sofía Sistemas', 'Saúl Sánchez'], [$test->basics, $test->ige, $test->c2, 'Beatriz Básicas', 'Bruno Benítez']] as [$department, $career, $campus, $teacherName, $studentName]) {
        $world['loads'][] = AcademicLoad::factory()->for($test->term)->create(['department_id' => $department->id]);
        $plan = StudyPlan::factory()->for($career)->create();
        $world['plans'][] = $plan;
        $teacher = Teacher::factory()->create(['name' => $teacherName]);
        TeacherDepartment::factory()->for($teacher)->for($department)->create(['is_primary' => true]);
        $world['teachers'][] = $teacher;
        $world['students'][] = Student::factory()->create(['name' => $studentName, 'study_plan_id' => $plan->id, 'career_id' => $career->id, 'campus_id' => $campus->id]);
    }

    return $world;
}

/**
 * Los permisos de lectura del directivo, de fábrica (spec «Demo SII v2», sección 4): lo académico que se consulta, sin
 * los archivos para el SII (de Servicios Escolares) y sin lo personal. De los candados del admin, solo ver Personas y
 * puestos, el Organigrama y la Bitácora (AZ-03, confirmado el 20 de noviembre de 2026).
 *
 * @return list<string>
 */
function directivoFactoryReads(): array
{
    return [
        'academic.career.view',
        'academic.department.view',
        'academic.study_plan.view',
        'academic.subject.view',
        'academic_load.agreement.view',
        'academic_load.demand.view_career',
        'academic_load.demand.view_department',
        'academic_load.forecast_evaluation.view',
        'academic_load.load.export',
        'academic_load.load.view',
        'academic_load.proposal.view',
        'academic_load.proposal_review.view',
        'catalog_validation.progress.view',
        'catalog_validation.review.view',
        'core.activity.view',
        'core.assignment.view',
        'core.campus.view',
        'core.modality.view',
        'core.org_unit.view',
        'core.term.view',
        'facilities.building.view',
        'facilities.room.view',
        'faculty.teacher.view',
        'faculty.teacher.view_all',
        'scheduling.schedule.view',
        'solver.career_run.view',
        'solver.department_run.view',
        'staffing.change_request.view',
        'staffing.ranking.view',
        'staffing.selection.view',
        'staffing.teacher_load.view',
        'students.student.view',
    ];
}

describe('R-V2-4: lectura en todo el Tec', function () {
    it('R-V2-4: el directivo de Planeación y Vinculación ve la carga, la retícula, los docentes y los alumnos de todos los departamentos y campus', function () {
        $world = directivoWorld($this);
        $gate = Gate::forUser($this->directivo);

        foreach ($world['loads'] as $load) {
            expect($gate->allows('view', $load))->toBeTrue();
        }

        foreach ($world['plans'] as $plan) {
            expect($gate->allows('view', $plan))->toBeTrue();
        }

        foreach ($world['teachers'] as $teacher) {
            expect($gate->allows('view', $teacher))->toBeTrue();
        }

        foreach ($world['students'] as $student) {
            expect($gate->allows('view', $student))->toBeTrue();
        }

        $this->actingAs($this->directivo);
        $actor = app(Actor::class);

        expect($actor->constrain(AcademicLoad::query(), AcademicLoadPermission::LoadView, ['modality' => 'modality_id', 'unit' => 'department_id'])->pluck('id')->sort()->values()->all())
            ->toBe(collect($world['loads'])->pluck('id')->sort()->values()->all())
            ->and($actor->allows(AcademicLoadPermission::LoadExport, Target::of(modality: $world['loads'][1]->modality_id, unit: $this->basics->id)))->toBeTrue();

        $this->get(route('academic.plans.show', $world['plans'][1]))->assertOk();
        $this->get(route('faculty.teachers'))->assertOk()->assertSee(['Sofía Sistemas', 'Beatriz Básicas']);
        $this->get(route('faculty.teachers.show', $world['teachers'][1]))->assertOk();
        $this->get(route('students.index'))->assertOk()->assertSee(['Saúl Sánchez', 'Bruno Benítez']);
        $this->get(route('students.show', $world['students'][1]))->assertOk();
    });

    it('R-V2-4: el directivo de la Dirección también lo ve todo, y en campus y modalidades solo los activos', function () {
        $world = directivoWorld($this);
        $director = User::factory()->withRole(Role::Directivo)->create();

        expect(RoleAssignment::query()->where('user_id', $director->id)->value('org_unit_id'))->toBe($this->direction->id);

        expect(Gate::forUser($director)->allows('view', $world['students'][0]))->toBeTrue();

        $this->c1->update(['is_active' => false]);
        app()->forgetScopedInstances();

        expect(Gate::forUser($director)->allows('view', $world['students'][0]->fresh()))->toBeFalse()
            ->and(Gate::forUser($director)->allows('view', $world['students'][1]))->toBeTrue();
    });
});

describe('R-V2-4: nada que escriba', function () {
    it('R-V2-4: el directivo no puede escribir: proponer, asignar docente, editar retícula y validar catálogo le dan 403', function () {
        $world = directivoWorld($this);
        $group = Group::factory()->for($this->term)->create(['campus_id' => $this->c1->id, 'subject_id' => Subject::factory()->create(['department_id' => $this->systems->id])->id, 'owner_career_id' => $this->isc->id]);
        $review = CatalogReview::factory()->for($this->term)->create(['department_id' => $this->systems->id]);
        $gate = Gate::forUser($this->directivo);

        expect($gate->allows('propose', $world['loads'][0]))->toBeFalse()
            ->and($gate->allows('close', $world['loads'][1]))->toBeFalse()
            ->and($gate->allows('create', [GroupTeacherAssignment::class, $group]))->toBeFalse()
            ->and($gate->allows('editGrid', $world['plans'][0]))->toBeFalse()
            ->and($gate->allows('validate', $review))->toBeFalse()
            ->and($gate->allows('update', $world['teachers'][0]))->toBeFalse()
            ->and($gate->allows('managePositions', $world['teachers'][1]))->toBeFalse()
            ->and($gate->allows('manageAvailability', $world['teachers'][1]))->toBeFalse()
            ->and($gate->allows('manageExternalLoads', $world['teachers'][1]))->toBeFalse();

        $this->actingAs($this->directivo);

        Livewire::test('academic::pages.plan', ['plan' => $world['plans'][0]->id])->call('openSpecialties')->assertForbidden();
        Livewire::test('faculty::pages.teachers')->call('create')->assertForbidden();
        Livewire::test('faculty::pages.teacher', ['teacher' => $world['teachers'][0]->id])->call('editData')->assertForbidden();
        Livewire::test('faculty::pages.teacher', ['teacher' => $world['teachers'][1]->id])->call('createPosition')->assertForbidden();

        $actor = app(Actor::class);
        $writes = array_values(array_filter(
            app(PermissionRegistry::class)->permissions(),
            fn (Permission $permission): bool => ! $permission->isReadOnly() && $actor->allowsAny($permission),
        ));

        expect(array_map(fn (Permission $permission): string => $permission->value, $writes))->toBe([]);
    });

    it('el panel no deja dar al directivo un permiso que no es de lectura', function () {
        $rules = app(PermissionGrantRules::class);

        expect($rules->grantProblem(Role::Directivo, StaffingPermission::TeacherAssign))
            ->toBe('«Asignar el docente de un grupo» no se da a Directivo: el puesto solo consulta, en todo el Tec, y el permiso no es de lectura.')
            ->and($rules->grantProblem(Role::Directivo, CorePermission::CampusViewInactive))->toBeNull()
            ->and($rules->grantProblem(Role::Directivo, AcademicLoadPermission::LoadExportSii))->toBeNull()
            ->and($rules->grantProblem(Role::Subdireccion, StaffingPermission::TeacherAssign))->toBeNull();

        $this->actingAs(User::factory()->admin()->create());

        expect(fn () => app(GrantPositionPermission::class)->handle(Role::Directivo, StaffingPermission::TeacherAssign))
            ->toThrow(ValidationException::class);

        DB::table('position_permissions')->insert(['role' => Role::Directivo->value, 'permission' => StaffingPermission::TeacherAssign->value]);
        app()->forgetScopedInstances();

        expect(app(GrantTable::class)->grants(Role::Directivo, StaffingPermission::TeacherAssign))->toBeFalse();
    });

    it('el registro falla al arrancar si grants() le da al directivo un permiso que no es de lectura', function () {
        expect(fn () => PermissionRegistry::fromDeclarations([StaffingPermission::class], [Role::Directivo->value => [StaffingPermission::TeacherAssign]], []))
            ->toThrow(LogicException::class, 'El permiso «staffing.teacher.assign» no es de lectura: no se concede a «directivo», que lee en todo el Tec (AZ-03).');
    });

    it('la lista de permisos de lectura es exactamente la esperada', function () {
        $registry = app(PermissionRegistry::class);
        $reads = array_values(array_map(
            fn (Permission $permission): string => $permission->value,
            array_filter($registry->permissions(), fn (Permission $permission): bool => $permission->isReadOnly()),
        ));
        sort($reads);

        // Lo que se lee: lo de la sección 4 del spec y Personas y puestos, Organigrama y Bitácora (AZ-03), más los archivos
        // para el SII, lo que sigue siendo solo del admin (roles, correos y colas, ver la carga académica oculta), lo que no es académico (campus y modalidades inactivos,
        // avisos y atajos del login, parámetros, lo no disponible del menú, importaciones) y lo personal.
        expect($reads)->toBe([
            'academic.career.view',
            'academic.department.view',
            'academic.study_plan.view',
            'academic.subject.view',
            'academic_load.agreement.view',
            'academic_load.demand.view_career',
            'academic_load.demand.view_department',
            'academic_load.forecast_evaluation.view',
            'academic_load.load.export',
            'academic_load.load.export_sii',
            'academic_load.load.view',
            'academic_load.proposal.view',
            'academic_load.proposal_review.view',
            'catalog_validation.progress.view',
            'catalog_validation.review.view',
            'core.academic_load.view_hidden',
            'core.activity.view',
            'core.assignment.view',
            'core.authz.view',
            'core.campus.view',
            'core.campus.view_inactive',
            'core.login_notice.view',
            'core.login_shortcut.view',
            'core.mail.view',
            'core.mail.view_mailboxes',
            'core.modality.view',
            'core.modality.view_inactive',
            'core.navigation.view_unavailable',
            'core.org_unit.view',
            'core.queue.view',
            'core.setting.view',
            'core.term.view',
            'facilities.building.view',
            'facilities.room.view',
            'faculty.teacher.view',
            'faculty.teacher.view_all',
            'imports.batch.view',
            'scheduling.schedule.view',
            'solver.career_run.view',
            'solver.department_run.view',
            'staffing.change_request.view',
            'staffing.my_load.view',
            'staffing.offer.view',
            'staffing.ranking.view',
            'staffing.selection.view',
            'staffing.teacher_load.view',
            'students.my_record.view',
            'students.student.view',
        ]);
    });

    it('R-V2-4: de fábrica, el directivo tiene exactamente la lectura de lo académico de la sección 4 del spec', function () {
        $registry = app(PermissionRegistry::class);
        $granted = array_values(array_map(
            fn (Permission $permission): string => $permission->value,
            array_filter($registry->permissions(), fn (Permission $permission): bool => $registry->grants(Role::Directivo, $permission)),
        ));
        sort($granted);

        expect($granted)->toBe(directivoFactoryReads())
            ->and(app(GrantTable::class)->matrix()[Role::Directivo->value])->toBe(directivoFactoryReads());
    });
});

describe('AZ-03: nombramiento', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->admin()->create(['name' => 'Ada Admin']));
        $this->rosa = User::factory()->create(['name' => 'Rosa Ruiz']);
    });

    it('el directivo se nombra sobre la Dirección o una subdirección no académica; sobre la Académica o un departamento se rechaza', function () {
        $appoint = fn (OrgUnit $unit, ?User $user = null): RoleAssignment => app(AppointRole::class)->handle($user ?? $this->rosa, Role::Directivo, null, $unit, null, CarbonImmutable::today(), null);
        $rules = app(RoleAssignmentRules::class);

        expect($appoint($this->administrative)->fresh())->role->toBe(Role::Directivo)->org_unit_id->toBe($this->administrative->id)
            ->and($appoint($this->direction, User::factory()->create())->fresh()->org_unit_id)->toBe($this->direction->id)
            ->and($rules->titularRoleOf($this->direction))->toBe(Role::Directivo)
            ->and($rules->titularRoleOf($this->planning))->toBe(Role::Directivo)
            ->and($rules->titularRoleOf($this->academic))->toBe(Role::Subdireccion)
            ->and($rules->rolesForUnit($this->academic))->not->toContain(Role::Directivo);

        $inactive = OrgUnit::factory()->inactive()->create(['code' => 'SUB-OTRA', 'name' => 'Subdirección Inactiva', 'kind' => OrgUnitKind::Subdireccion]);

        expect(fn () => $appoint($this->academic))->toThrow(fn (ValidationException $e) => expect($e->errors()['unit'])->toBe(['La Subdirección Académica tiene su propio puesto: el directivo va sobre la Dirección o una subdirección no académica.']))
            ->and(fn () => $appoint(OrgUnit::query()->findOrFail($this->systems->id)))->toThrow(fn (ValidationException $e) => expect($e->errors()['unit'])->toBe(['Sistemas y Computación no es la Dirección ni una subdirección.']))
            ->and(fn () => $appoint($inactive))->toThrow(fn (ValidationException $e) => expect($e->errors()['unit'])->toBe(['Subdirección Inactiva está inactiva.']))
            ->and(fn () => app(AppointRole::class)->handle($this->rosa, Role::Directivo, $this->c2, $this->planning, null, CarbonImmutable::today(), null))
            ->toThrow(fn (ValidationException $e) => expect($e->errors()['campus'])->toBe(['Este puesto no lleva campus.']));
    });

    it('un solo directivo vigente por unidad', function () {
        $beto = User::factory()->create(['name' => 'Beto Rentería']);
        vacate(Role::Directivo, $this->planning->id);
        RoleAssignment::factory()->for($beto)->create(['role' => Role::Directivo, 'org_unit_id' => $this->planning->id, 'valid_from' => today()->toDateString()]);

        expect(fn () => app(AppointRole::class)->handle($this->rosa, Role::Directivo, null, $this->planning, null, CarbonImmutable::today(), null))
            ->toThrow(fn (ValidationException $e) => expect($e->errors()['unit'])->toBe(['Subdirección de Planeación y Vinculación ya tiene titular en esas fechas: Beto Rentería (desde '.today()->format('d/m/Y').'). Termina ese nombramiento antes de nombrar a otra persona.']));

        expect(fn () => DB::transaction(fn () => DB::table('role_assignments')->insert(['user_id' => $this->rosa->id, 'role' => Role::Directivo->value, 'org_unit_id' => $this->planning->id, 'valid_from' => today()->addDay()->toDateString()])))
            ->toThrow(QueryException::class, 'role_assignments_one_directivo')
            ->and(fn () => DB::transaction(fn () => DB::table('role_assignments')->insert(['user_id' => $this->rosa->id, 'role' => Role::Directivo->value, 'valid_from' => today()->toDateString()])))
            ->toThrow(QueryException::class, 'role_assignments_scope_complete');

        expect(app(AppointRole::class)->handle($this->rosa, Role::Directivo, null, $this->administrative, null, CarbonImmutable::today(), null)->org_unit_id)
            ->toBe($this->administrative->id);
    });
});

describe('R-V2-4: credencial', function () {
    it('la credencial del directivo nombra su unidad', function () {
        $user = User::factory()->withRole(Role::Directivo, ['org_unit_id' => $this->administrative->id])->create(['name' => 'Sergio Servicios']);
        $credential = domOf($this->actingAs($user)->get('/'))->querySelector('[data-testid="credential"]');

        expect(textOf($credential))->toBe('SS Sergio Servicios Subdirección de Servicios Administrativos · Ene-Jun 2027');

        Livewire::test('core::pages.home')
            ->assertSeeInOrder(['Subdirección de Servicios Administrativos', 'Consultas en modo lectura todo lo académico del Tec.']);
    });

    it('R-V2-4: su menú tiene lo académico de lectura, y Personas y puestos, Organigrama y Bitácora (AZ-03), sin lo demás del admin', function () {
        $this->actingAs($this->directivo);
        $menu = array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));

        expect($menu)->toContain('Docentes', 'Alumnos', 'Planes de estudio', 'Departamentos', 'Carreras', 'Materias', 'Personas y puestos', 'Organigrama', 'Bitácora')
            ->not->toContain('Puestos y permisos', 'Correos y colas', 'Materias por colocar', 'Importaciones');
    });
});
