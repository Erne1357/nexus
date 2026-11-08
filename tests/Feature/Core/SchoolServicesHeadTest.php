<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\NotificationQuery;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| AZ-02 (D20, confirmado el 5 de noviembre de 2026) visto desde las pantallas: el jefe del Departamento de Servicios
| Escolares solo tiene lo de Servicios Escolares (hoy, «Por validar» en los planes de estudio); su menú no muestra lo
| académico, no entra a esas pantallas ni las usa aunque llame a mano, y su credencial nombra su unidad. Los demás
| puestos ven el mismo menú que antes.
*/

beforeEach(function () {
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->services = OrgUnit::factory()->create([
        'code' => OrgTree::SCHOOL_SERVICES, 'name' => 'Departamento de Servicios Escolares', 'kind' => OrgUnitKind::Departamento,
        'parent_id' => app(OrgTree::class)->idOf(OrgTree::ACADEMIC),
    ]);
    $this->systems = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->systems->id]);

    $this->servicesHead = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->services->id])->create(['name' => 'Sara Escolar']);
    $this->academicHead = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->systems->id])->create(['name' => 'Juan Pérez']);
});

/**
 * @return list<string>
 */
function menuOf(User $user): array
{
    test()->actingAs($user);

    return array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class)));
}

describe('AZ-02: menú', function () {
    it('AZ-02: el jefe de Servicios Escolares no ve lo académico: solo Inicio y Planes de estudio', function () {
        expect(menuOf($this->servicesHead))->toBe(['Inicio', 'Planes de estudio']);
    });

    it('AZ-S16: los demás puestos ven el mismo menú que antes', function (Closure $user, array $menu) {
        expect(menuOf($user($this)))->toBe($menu);
    })->with([
        'jefe de un departamento académico' => [fn ($test) => $test->academicHead, ['Inicio', 'Docentes', 'Alumnos', 'Validar catálogos', 'Bandeja del departamento', 'Choques y avance', 'Solicitudes de cambio']],
        'subdirección' => [fn () => User::factory()->withRole(Role::Subdireccion)->create(), ['Inicio', 'Campus', 'Periodos', 'Edificios', 'Salones', 'Docentes', 'Alumnos', 'Validar catálogos', 'Avance de validación', 'Propuesta de carrera', 'Bandeja del departamento', 'Choques y avance', 'Solicitudes de cambio']],
        'jefe de proyecto docente' => [fn ($test) => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $test->c2->id, 'department_id' => $test->systems->id])->create(), ['Inicio', 'Docentes', 'Bandeja del departamento', 'Choques y avance', 'Solicitudes de cambio']],
        'coordinador' => [fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->c2->id, 'career_id' => $test->isc->id])->create(), ['Inicio', 'Alumnos', 'Propuesta de carrera', 'Choques y avance', 'Solicitudes de cambio']],
        'docente' => [fn () => User::factory()->withRole(Role::Docente)->create(), ['Inicio']],
        'admin' => [fn () => User::factory()->admin()->create(), ['Inicio', 'Campus', 'Periodos', 'Organigrama', 'Personas y puestos', 'Departamentos', 'Carreras', 'Materias', 'Planes de estudio', 'Edificios', 'Salones', 'Docentes', 'Alumnos', 'Importaciones', 'Validar catálogos', 'Avance de validación', 'Propuesta de carrera', 'Bandeja del departamento', 'Choques y avance', 'Solicitudes de cambio']],
    ]);
});

describe('AZ-02: pantallas y acciones', function () {
    it('AZ-02: el jefe de Servicios Escolares no entra a ninguna pantalla académica', function (string $route) {
        $this->actingAs($this->servicesHead)->get(route($route))->assertForbidden();
    })->with([
        'catalog-validation.review', 'catalog-validation.progress', 'academic-load.inbox', 'academic-load.proposal',
        'faculty.teachers', 'students.index', 'academic.departments', 'academic.careers', 'academic.subjects',
    ]);

    it('AZ-02: el jefe de Servicios Escolares no abre la ficha de un docente ni la de un alumno', function () {
        $teacher = Teacher::factory()->create();
        TeacherDepartment::factory()->for($teacher)->for($this->systems)->create(['is_primary' => true]);
        $student = Student::factory()->for(StudyPlan::factory()->for($this->isc), 'studyPlan')->create(['campus_id' => $this->c2->id]);

        $this->actingAs($this->servicesHead)->get(route('faculty.teachers.show', $teacher))->assertForbidden();
        $this->actingAs($this->servicesHead)->get(route('students.show', $student))->assertForbidden();
    });

    it('AZ-02: sí entra a la lista de planes y a la retícula, que es lo de «Por validar»', function () {
        $plan = StudyPlan::factory()->for($this->isc)->create();

        $this->actingAs($this->servicesHead)->get(route('academic.plans'))->assertOk();
        $this->actingAs($this->servicesHead)->get(route('academic.plans.show', $plan))->assertOk();
    });

    it('AZ-02: no da de alta docentes aunque llame con la página que abrió el jefe de un departamento académico', function () {
        $page = Livewire::actingAs($this->academicHead)->test('faculty::pages.teachers');

        $this->actingAs($this->servicesHead);
        $page->call('create')->assertForbidden();
    });

    it('AZ-02: las policies académicas le dicen que no', function () {
        $gate = Gate::forUser($this->servicesHead);

        expect($gate->allows('viewAny', Teacher::class))->toBeFalse()
            ->and($gate->allows('create', Teacher::class))->toBeFalse()
            ->and($gate->allows('create', [Teacher::class, $this->systems]))->toBeFalse()
            ->and($gate->allows('viewAny', Student::class))->toBeFalse()
            ->and($gate->allows('viewAny', StudyPlan::class))->toBeTrue()
            ->and($gate->allows('validatePlacements', StudyPlan::class))->toBeTrue()
            ->and(Gate::forUser($this->academicHead)->allows('validatePlacements', StudyPlan::class))->toBeFalse();
    });
});

describe('AZ-02: credencial', function () {
    it('AZ-02: nombra su unidad: «Jefe del Departamento de Servicios Escolares»', function () {
        $credential = domOf($this->actingAs($this->servicesHead)->get('/'))->querySelector('[data-testid="credential"]');

        expect(textOf($credential))->toBe('SE Sara Escolar Jefe del Departamento de Servicios Escolares · Campus II · Ene-Jun 2027');
    });

    it('AZ-S16: el jefe de un departamento académico conserva su nombre de puesto', function () {
        $credential = domOf($this->actingAs($this->academicHead)->get('/'))->querySelector('[data-testid="credential"]');

        expect(textOf($credential))->toBe('JP Juan Pérez Jefe de departamento académico · Campus II · Ene-Jun 2027');
    });

    it('AZ-02: Inicio lo nombra igual y dice lo que hace', function () {
        $this->actingAs($this->servicesHead);

        Livewire::test('core::pages.home')
            ->assertSeeInOrder(['Jefe del Departamento de Servicios Escolares', 'Asignas semestre a las materias de la migración del SII'])
            ->assertDontSee('Jefe de departamento académico')
            ->assertDontSee('Validas los catálogos');
    });

    it('AZ-02: en «Actuando como» su renglón nombra la unidad una sola vez', function () {
        vacate(Role::JefeDepartamento, $this->services->id);
        $user = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->services->id, 'valid_from' => today()->toDateString()])->withRole(Role::Docente)->create();
        actingAsRole($user, Role::JefeDepartamento);

        Livewire::test('core::components.context-switcher')
            ->assertViewHas('roles', fn (array $roles): bool => array_column($roles, 'name') === ['Jefe del Departamento de Servicios Escolares', 'Docente']);
    });
});

describe('AZ-02: su nombre en las pantallas de puestos, el cambio de perfil y los avisos', function () {
    beforeEach(function () {
        $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
    });

    it('«Nombrar» en el panel de Servicios Escolares ofrece «Jefe del Departamento de Servicios Escolares»; en un departamento académico, como antes', function () {
        $panel = Livewire::actingAs($this->admin)->test('core::components.org-unit-panel');

        $panel->dispatch('org-unit-picked', id: $this->services->id)->call('openAppoint')
            ->assertViewHas('offered', fn (array $offered): bool => $offered === [['id' => 'jefe_departamento', 'name' => 'Jefe del Departamento de Servicios Escolares']]);

        $panel->dispatch('org-unit-picked', id: $this->systems->id)->call('openAppoint')
            ->assertViewHas('offered', fn (array $offered): bool => array_column($offered, 'name') === ['Jefe de departamento académico', 'Jefe de proyecto docente']);
    });

    it('las listas para elegir un puesto dicen «Jefe de departamento», que cubre a los dos', function () {
        $names = fn (array $roles): array => array_column($roles, 'name');

        Livewire::actingAs($this->admin)->test('core::pages.people')
            ->assertViewHas('roles', fn (array $roles): bool => in_array('Jefe de departamento', $names($roles), true) && ! in_array('Jefe de departamento académico', $names($roles), true));

        Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->academicHead->id])
            ->assertViewHas('whoRoles', fn (array $roles): bool => in_array('Jefe de departamento', $names($roles), true))
            ->assertViewHas('offered', fn (array $roles): bool => in_array('Jefe de departamento', $names($roles), true));
    });

    it('«¿Quién ocupa un puesto?» nombra al jefe de Servicios Escolares por su unidad', function () {
        vacate(Role::JefeDepartamento, $this->services->id);

        Livewire::actingAs($this->admin)->test('core::pages.person', ['user' => $this->academicHead->id])
            ->set('whoRole', 'jefe_departamento')
            ->set('whoUnit', $this->services->id)
            ->assertSee('Sin jefe del departamento de servicios escolares vigente')
            ->assertSee('Nadie ocupa Jefe del Departamento de Servicios Escolares hoy.')
            ->set('whoUnit', $this->systems->id)
            ->assertSee('Juan Pérez');
    });

    it('la bitácora del cambio de perfil lo nombra por su unidad, y al jefe académico como antes', function (string $who, string $description) {
        $user = $who === 'services' ? $this->servicesHead : $this->academicHead;
        RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Docente]);
        actingAsRole($user, Role::Docente);

        app(SwitchRole::class)->handle(Role::JefeDepartamento);

        expect(Activity::latest('id')->first()->description)->toBe($description);
    })->with([
        'Servicios Escolares' => ['services', 'Cambió al perfil Jefe del Departamento de Servicios Escolares'],
        'departamento académico' => ['academic', 'Cambió al perfil Jefe de departamento académico'],
    ]);

    it('la campana etiqueta sus avisos de jefe con el nombre de su unidad', function () {
        RoleAssignment::factory()->create(['user_id' => $this->servicesHead->id, 'role' => Role::Docente]);
        $data = ['title' => 'Por validar', 'body' => 'x', 'icon' => 'o-bell', 'tone' => 'info', 'route' => 'home', 'parameters' => [], 'term_id' => null, 'campus_id' => null, 'role' => 'jefe_departamento'];
        $this->servicesHead->notifications()->create(['id' => (string) Str::uuid7(), 'type' => 'Tests\\SchoolServicesNotice', 'data' => $data]);
        $this->academicHead->notifications()->create(['id' => (string) Str::uuid7(), 'type' => 'Tests\\SchoolServicesNotice', 'data' => $data]);

        expect(app(NotificationQuery::class)->recent($this->servicesHead)->sole()['role'])->toBe('Jefe del Departamento de Servicios Escolares')
            ->and(app(NotificationQuery::class)->recent($this->academicHead)->sole()['role'])->toBe('Jefe de departamento académico');
    });
});
