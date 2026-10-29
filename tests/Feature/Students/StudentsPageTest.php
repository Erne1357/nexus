<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Listado de alumnos (`/students`, students::pages.students; tarea 1.6). Solo lectura, de un campus: el activo de
| la credencial. Quién ve a quién, según reglas-de-negocio.md, "Aprobaciones y permisos" (fila "Consultar
| alumnos"): el admin, todos; la subdirección, su campus; el jefe de departamento, las carreras de su
| departamento; el coordinador, su carrera. El jefe de proyecto docente y el docente no entran.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->other = Campus::factory()->create(['code' => 'C1']);
    $department = Department::factory()->create();
    $this->isc = Career::factory()->for($department)->create(['code' => 'ISC', 'name' => 'Sistemas']);
    $this->ind = Career::factory()->create(['code' => 'IND']);
    $iscPlan = StudyPlan::factory()->for($this->isc)->create(['code' => 'ISC-2010']);
    Student::factory()->for($iscPlan)->create(['control_number' => '24111234', 'name' => 'Daniela Ortiz', 'campus_id' => $this->campus->id, 'current_semester' => 5]);
    Student::factory()->for($iscPlan)->create(['control_number' => '24111241', 'name' => 'Luis Ávila', 'campus_id' => $this->campus->id, 'status' => StudentStatus::BajaTemporal]);
    Student::factory()->for(StudyPlan::factory()->for($this->ind))->create(['control_number' => '24111258', 'name' => 'María Carrillo', 'campus_id' => $this->campus->id, 'status' => null]);
    Student::factory()->for($iscPlan)->create(['control_number' => '24119999', 'campus_id' => $this->other->id]);

    $this->admin = User::factory()->admin()->create();
    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->isc->id])->create();
});

/**
 * @return Closure(mixed): bool
 */
function listsStudents(array $controlNumbers): Closure
{
    return fn ($students): bool => $students->pluck('control_number')->all() === $controlNumbers;
}

function studentsPageAs(User $user, ?Campus $campus = null): Testable
{
    if ($campus !== null) {
        session([ScopedContext::ACTIVE_CAMPUS => $campus->id]);
    }

    return Livewire::actingAs($user)->test('students::pages.students');
}

describe('admin', function () {
    it('sin campus en la credencial, pide elegir uno', function () {
        studentsPageAs($this->admin)
            ->assertSee('Elige un campus en tu credencial para ver a sus alumnos.')
            ->assertViewHas('students', null);
    });

    it('lista a los alumnos del campus de la credencial, con su estatus, y no los de otro campus', function () {
        studentsPageAs($this->admin, $this->campus)
            ->assertViewHas('students', listsStudents(['24111234', '24111241', '24111258']))
            ->assertSee('Daniela Ortiz')
            ->assertSee('Baja temporal')
            ->assertSee('Sin estatus')
            ->assertDontSee('24119999');
    });

    it('filtra por carrera, semestre y estatus, y vuelve a la primera página', function () {
        studentsPageAs($this->admin, $this->campus)
            ->set('career', $this->isc->id)
            ->assertViewHas('students', listsStudents(['24111234', '24111241']))
            ->set('semester', '5')
            ->assertViewHas('students', listsStudents(['24111234']))
            ->set('career', '')
            ->set('semester', '')
            ->set('status', 'sin_estatus')
            ->assertViewHas('students', listsStudents(['24111258']));
    });

    it('filtra por plan, y al cambiar un filtro vuelve a la primera página', function () {
        $plan = StudyPlan::factory()->for($this->isc)->create(['code' => 'ISC-2020']);
        foreach (range(1, 26) as $i) {
            Student::factory()->for($plan)->create(['control_number' => sprintf('2412%04d', $i), 'campus_id' => $this->campus->id]);
        }

        studentsPageAs($this->admin, $this->campus)
            ->call('gotoPage', 2)
            ->set('plan', $plan->id)
            ->assertSet('paginators.page', 1)
            ->assertViewHas('students', fn ($students): bool => $students->total() === 26);
    });

    it('olvida un filtro que no aplica en el campus de la credencial, en lugar de dejar la lista vacía sin decir por qué', function () {
        studentsPageAs($this->admin, $this->other)
            ->set('career', $this->ind->id)
            ->assertSet('career', '')
            ->assertViewHas('students', listsStudents(['24119999']));
    });

    it('sin alumnos en el campus, dice que llegan con la importación; con una búsqueda sin resultados, que nada coincide', function () {
        studentsPageAs($this->admin, Campus::factory()->create())
            ->assertSee('Este campus todavía no tiene alumnos: llegan con la importación de alumnos del SII.');

        studentsPageAs($this->admin, $this->campus)
            ->set('search', 'nadie se llama así')
            ->assertSee('Ningún alumno coincide con la búsqueda.');
    });

    it('busca por número de control o nombre', function () {
        studentsPageAs($this->admin, $this->campus)
            ->set('search', 'ávila')
            ->assertViewHas('students', listsStudents(['24111241']));
    });

    it('un filtro alterado desde el navegador no truena: se olvida', function () {
        studentsPageAs($this->admin, $this->campus)
            ->set('career', 'no-es-un-uuid')
            ->assertOk()
            ->assertSet('career', '')
            ->assertViewHas('students', listsStudents(['24111234', '24111241', '24111258']));
    });
});

describe('coordinador', function () {
    it('ve solo a los alumnos de su carrera, y su campus es el de la credencial sin elegirlo', function () {
        studentsPageAs($this->coordinator)
            ->assertViewHas('students', listsStudents(['24111234', '24111241']))
            ->assertDontSee('María Carrillo');
    });

    it('en los filtros solo le ofrece su carrera', function () {
        studentsPageAs($this->coordinator)
            ->assertViewHas('options', fn (array $options): bool => array_column($options['careers'], 'name') === ['ISC · Sistemas']);
    });
});

describe('sin acceso', function () {
    it('no deja entrar al jefe de proyecto docente ni al docente', function (Role $role) {
        $user = User::factory()->withRole($role, ['campus_id' => $this->campus->id, 'department_id' => $this->isc->department_id])->create();

        $this->actingAs($user)->get(route('students.index'))->assertForbidden();
    })->with([Role::JefeProyectoDocente, Role::Docente]);

    it('manda al invitado a iniciar sesión', function () {
        $this->get(route('students.index'))->assertRedirect(route('login'));
    });

    it('a la subdirección de otro campus no le muestra a los alumnos de este, aunque lo elija', function () {
        $elsewhere = User::factory()->withRole(Role::Subdireccion, ['campus_id' => $this->other->id])->create();

        studentsPageAs($elsewhere, $this->campus)
            ->assertViewHas('students', listsStudents(['24119999']));
    });
});

describe('menú', function () {
    it('la entrada Alumnos va en Servicios escolares para quien puede consultar alumnos, y no para los demás', function () {
        $teacherHead = User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $this->campus->id, 'department_id' => $this->isc->department_id])->create();

        $this->actingAs($this->coordinator)->get(route('home'))->assertSee(route('students.index'));
        $this->actingAs($teacherHead)->get(route('home'))->assertDontSee(route('students.index'));
    });
});
