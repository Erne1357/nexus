<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Livewire\Livewire;

/*
| Ficha del alumno (`/students/{alumno}`, students::pages.student; tarea 1.6): sus datos y cifras, su retícula por
| semestre con el estado de cada materia como lo nombra el SII y su kárdex por periodo. Solo lectura. La ve quien
| puede verlo según StudentPolicy (reglas-de-negocio.md, "Consultar alumnos"); nadie más, ni con el enlace.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->department = Department::factory()->create();
    $this->career = Career::factory()->for($this->department)->create(['code' => 'ISIC', 'name' => 'Ingeniería en Sistemas']);
    $plan = StudyPlan::factory()->for($this->career)->create(['code' => 'ISIC-2010-224', 'total_credits' => 260]);
    $this->calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'credits' => 5]);
    $this->integral = Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Cálculo Integral', 'credits' => 5]);
    $this->circuits = Subject::factory()->create(['code' => 'SCD-1018', 'name' => 'Principios Eléctricos', 'credits' => 5]);
    placeInPlan($plan, $this->calculus);
    placeInPlan($plan, $this->integral, 2);
    placeInPlan($plan, $this->circuits, 4);
    seriate($plan, $this->integral, $this->calculus);
    $this->student = Student::factory()->for($plan)->create(['control_number' => '24111234', 'name' => 'Daniela Ortiz', 'campus_id' => $this->campus->id, 'current_semester' => 1]);
    KardexRecord::factory()->for($this->student)->for($this->calculus)->create(['term_code' => '20253', 'grade' => 88]);

    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
});

it('muestra los datos del alumno, sus cifras y su retícula con el estado de cada materia', function () {
    Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
        ->assertSee('Daniela Ortiz')
        ->assertSee('24111234')
        ->assertSee('ISIC-2010-224')
        ->assertSee('Semestre 1')
        ->assertSee('Semestre 4')
        ->assertSeeInOrder(['ACF-0901', 'Cálculo Diferencial', 'Acreditada'])
        ->assertSeeInOrder(['ACF-0902', 'Cálculo Integral', 'Puede seleccionarla'])
        ->assertSeeInOrder(['SCD-1018', 'No permitida aún', 'Fuera de su avance']);
});

it('no lleva textos de ayuda: la retícula se explica con su leyenda y el puntero', function () {
    Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
        ->assertDontSee('Puede seleccionar materias hasta el semestre')
        ->assertDontSee('Toca una materia')
        ->assertDontSee('Esc la quita');
});

it('los encabezados de semestre solo dicen el semestre y cuántas acreditó; el suyo lleva su fondo y lo dice a lectores de pantalla', function () {
    $page = domOf(Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id]));
    $header = fn (int $semester) => $page->querySelector('section[data-semester="'.$semester.'"] h3');

    expect(textOf($header(1)))->toBe('Semestre 1, su semestre 1 de 1')
        ->and(textOf($header(1)->querySelector('.sr-only')))->toBe(', su semestre')
        ->and(textOf($header(4)))->toBe('Semestre 4 0 de 1')
        ->and($page->querySelector('[data-column="1"]')->getAttribute('class'))->toContain('bg-base-200')
        ->and($page->querySelector('[data-column="4"]')->getAttribute('class'))->not->toContain('bg-base-200')
        ->and(textOf($page->querySelector('[data-testid="student-grid"]')))->not->toContain('Su semestre');
});

it('cada especialidad tiene una banda con su nombre y cuántas materias tiene, y sus mosaicos no lo repiten', function () {
    $specialty = Specialty::factory()->for($this->student->studyPlan)->create(['name' => 'Ciencia de Datos e Inteligencia Artificial Aplicada']);
    placeInPlan($this->student->studyPlan, Subject::factory()->create(['code' => 'DAD-2401']), 2, $specialty);
    placeInPlan($this->student->studyPlan, Subject::factory()->create(['code' => 'DAD-2402']), 4, $specialty);

    $page = domOf(Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id]));

    expect(textOf($page->querySelector('[data-band="'.$specialty->id.'"]')))->toBe('Especialidad Ciencia de Datos e Inteligencia Artificial Aplicada 2 materias')
        ->and(textOf($page->querySelector('section[data-semester="2"] [data-separator="'.$specialty->id.'"]')))->toContain('Ciencia de Datos e Inteligencia Artificial Aplicada')
        ->and(textOf($page->querySelector('[data-subject="DAD-2401"]')))->not->toContain('Ciencia de Datos');
});

it('cada cadena de seriación tiene su color en la franja de sus materias y en la leyenda, con su nombre', function () {
    $page = domOf(Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id]));
    $tile = fn (string $code) => $page->querySelector('[data-subject="'.$code.'"]');

    expect(textOf($page->querySelector('[data-testid="chain-legend"] [data-chain="0"]')))->toBe('Cálculo Diferencial 2')
        ->and($tile('ACF-0901')->getAttribute('data-chain'))->toBe('0')
        ->and($tile('ACF-0902')->getAttribute('data-chain'))->toBe('0')
        ->and($tile('ACF-0902')->getAttribute('data-prereqs'))->toBe('ACF-0901')
        ->and($tile('ACF-0901')->getAttribute('class'))->toContain('shadow-[inset_5px_0_0_var(--chain)]')
        ->and($tile('ACF-0901')->getAttribute('style'))->toContain('--chain: var(--color-chain-1)')
        ->and($tile('SCD-1018')->hasAttribute('data-chain'))->toBeFalse()
        ->and($tile('SCD-1018')->getAttribute('class'))->not->toContain('--chain')
        ->and($tile('SCD-1018')->getAttribute('style'))->not->toContain('--chain');
});

it('dice qué le falta a una materia que todavía no puede cursar', function () {
    KardexRecord::query()->delete();

    Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeInOrder(['ACF-0902', 'Pide', 'ACF-0901']);
});

it('en la pestaña Kárdex, que va en la URL, muestra el historial por periodo', function () {
    Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
        ->assertDontSee('Ago-Dic 2025');

    Livewire::actingAs($this->coordinator)->withQueryParams(['tab' => 'kardex'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeInOrder(['20253', 'Ago-Dic 2025', 'ACF-0901', 'Cálculo Diferencial', '88', 'Aprobada', '1 · ordinaria']);
});

it('una pestaña que no existe en la URL muestra la retícula', function () {
    Livewire::actingAs($this->coordinator)->withQueryParams(['tab' => 'otra'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSet('tab', 'reticula')
        ->assertSee('Cálculo Integral')
        ->assertDontSee('Ago-Dic 2025');
});

it('el listado lleva a la ficha de cada alumno', function () {
    $this->actingAs($this->coordinator)->get(route('students.index'))
        ->assertOk()
        ->assertSee(route('students.show', $this->student));
});

describe('sin acceso', function () {
    it('no la ve quien no cubre al alumno, ni con el enlace: :dataset', function (Closure $user) {
        $this->actingAs($user($this))->get(route('students.show', $this->student))->assertForbidden();
    })->with([
        'el coordinador de otra carrera' => [fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->campus->id, 'career_id' => Career::factory()->create()->id])->create()],
        'la subdirección de otro campus' => [fn ($test) => User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create()],
        'el jefe de proyecto docente' => [fn ($test) => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $test->campus->id, 'department_id' => $test->department->id])->create()],
    ]);

    it('un id que no es de un alumno, o uno borrado, no existe', function () {
        $this->actingAs($this->coordinator)->get('/students/no-es-un-uuid')->assertNotFound();
        $this->student->delete();
        $this->actingAs($this->coordinator)->get(route('students.show', $this->student->id))->assertNotFound();
    });

    it('manda al invitado a iniciar sesión', function () {
        $this->get(route('students.show', $this->student))->assertRedirect(route('login'));
    });
});
