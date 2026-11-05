<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/*
| D20 (confirmado el 5 de noviembre de 2026) visto desde las pantallas: el jefe del Departamento de Servicios
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

describe('menú', function () {
    it('el jefe de Servicios Escolares solo ve Inicio y Planes de estudio', function () {
        expect(menuOf($this->servicesHead))->toBe(['Inicio', 'Planes de estudio']);
    });

    it('los demás puestos ven el mismo menú que antes', function (Closure $user, array $menu) {
        expect(menuOf($user($this)))->toBe($menu);
    })->with([
        'jefe de un departamento académico' => [fn ($test) => $test->academicHead, ['Inicio', 'Docentes', 'Alumnos', 'Validar catálogos', 'Bandeja del departamento']],
        'subdirección' => [fn () => User::factory()->withRole(Role::Subdireccion)->create(), ['Inicio', 'Campus', 'Periodos', 'Edificios', 'Salones', 'Docentes', 'Alumnos', 'Validar catálogos', 'Avance de validación', 'Propuesta de carrera', 'Bandeja del departamento']],
        'jefe de proyecto docente' => [fn ($test) => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $test->c2->id, 'department_id' => $test->systems->id])->create(), ['Inicio', 'Docentes', 'Bandeja del departamento']],
        'coordinador' => [fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->c2->id, 'career_id' => $test->isc->id])->create(), ['Inicio', 'Alumnos', 'Propuesta de carrera']],
        'docente' => [fn () => User::factory()->withRole(Role::Docente)->create(), ['Inicio']],
        'admin' => [fn () => User::factory()->admin()->create(), ['Inicio', 'Campus', 'Periodos', 'Organigrama', 'Personas y puestos', 'Departamentos', 'Carreras', 'Materias', 'Planes de estudio', 'Edificios', 'Salones', 'Docentes', 'Alumnos', 'Importaciones', 'Validar catálogos', 'Avance de validación', 'Propuesta de carrera', 'Bandeja del departamento']],
    ]);
});

describe('pantallas y acciones', function () {
    it('el jefe de Servicios Escolares no entra a ninguna pantalla académica', function (string $route) {
        $this->actingAs($this->servicesHead)->get(route($route))->assertForbidden();
    })->with([
        'catalog-validation.review', 'catalog-validation.progress', 'academic-load.inbox', 'academic-load.proposal',
        'faculty.teachers', 'students.index', 'academic.departments', 'academic.careers', 'academic.subjects',
    ]);

    it('el jefe de Servicios Escolares no abre la ficha de un docente ni la de un alumno', function () {
        $teacher = Teacher::factory()->create();
        TeacherDepartment::factory()->for($teacher)->for($this->systems)->create(['is_primary' => true]);
        $student = Student::factory()->for(StudyPlan::factory()->for($this->isc), 'studyPlan')->create(['campus_id' => $this->c2->id]);

        $this->actingAs($this->servicesHead)->get(route('faculty.teachers.show', $teacher))->assertForbidden();
        $this->actingAs($this->servicesHead)->get(route('students.show', $student))->assertForbidden();
    });

    it('sí entra a la lista de planes y a la retícula, que es lo de «Por validar»', function () {
        $plan = StudyPlan::factory()->for($this->isc)->create();

        $this->actingAs($this->servicesHead)->get(route('academic.plans'))->assertOk();
        $this->actingAs($this->servicesHead)->get(route('academic.plans.show', $plan))->assertOk();
    });

    it('no da de alta docentes aunque llame con la página que abrió el jefe de un departamento académico', function () {
        $page = Livewire::actingAs($this->academicHead)->test('faculty::pages.teachers');

        $this->actingAs($this->servicesHead);
        $page->call('create')->assertForbidden();
    });

    it('las policies académicas le dicen que no', function () {
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

describe('credencial', function () {
    it('nombra su unidad: «Jefe del Departamento de Servicios Escolares»', function () {
        $credential = domOf($this->actingAs($this->servicesHead)->get('/'))->querySelector('[data-testid="credential"]');

        expect(textOf($credential))->toBe('SE Sara Escolar Jefe del Departamento de Servicios Escolares · Campus II · Ene-Jun 2027');
    });

    it('el jefe de un departamento académico conserva su nombre de puesto', function () {
        $credential = domOf($this->actingAs($this->academicHead)->get('/'))->querySelector('[data-testid="credential"]');

        expect(textOf($credential))->toBe('JP Juan Pérez Jefe de departamento académico · Campus II · Ene-Jun 2027');
    });

    it('Inicio lo nombra igual y dice lo que hace', function () {
        $this->actingAs($this->servicesHead);

        Livewire::test('core::pages.home')
            ->assertSeeInOrder(['Jefe del Departamento de Servicios Escolares', 'Asignas semestre a las materias de la migración del SII'])
            ->assertDontSee('Jefe de departamento académico')
            ->assertDontSee('Validas los catálogos');
    });

    it('en «Actuando como» su renglón nombra la unidad una sola vez', function () {
        vacate(Role::JefeDepartamento, $this->services->id);
        $user = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $this->services->id, 'valid_from' => today()->toDateString()])->withRole(Role::Docente)->create();
        actingAsRole($user, Role::JefeDepartamento);

        Livewire::test('core::components.context-switcher')
            ->assertViewHas('roles', fn (array $roles): bool => array_column($roles, 'name') === ['Jefe del Departamento de Servicios Escolares', 'Docente']);
    });
});
