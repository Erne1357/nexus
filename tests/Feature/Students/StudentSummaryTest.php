<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Livewire\Livewire;

/*
| El tablero «Resumen» (AC-15 y AC-24, tarea 1.67): el Resumen del propio alumno (`/resumen`, Core lo pinta con HomePanels) y la
| pestaña «Resumen» de la ficha del personal, que es la primera y por omisión, en tercera persona y sin «Tu acceso».
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->department = Department::factory()->create();
    $this->career = Career::factory()->for($this->department)->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas']);
    $this->plan = StudyPlan::factory()->for($this->career)->create(['code' => 'ISIC-2010-224', 'total_credits' => 260]);
    $this->subject = function (string $code, string $name, int $semester = 1) {
        $subject = Subject::factory()->create(['code' => $code, 'name' => $name, 'credits' => 5]);
        placeInPlan($this->plan, $subject, $semester);

        return $subject;
    };
    $this->student = Student::factory()->for($this->plan)->create(['control_number' => '24111234', 'name' => 'Daniela Ortiz', 'career_id' => $this->career->id, 'campus_id' => $this->campus->id, 'current_semester' => 1]);

    $calculus = ($this->subject)('ACF-0901', 'Cálculo Diferencial');
    $physics = ($this->subject)('ACF-0903', 'Física General');
    $algebra = ($this->subject)('ACF-0904', 'Álgebra Lineal');
    KardexRecord::factory()->for($this->student)->for($calculus)->create(['term_code' => '20251', 'grade' => 90, 'result' => KardexResult::Aprobada]);
    KardexRecord::factory()->for($this->student)->for($physics)->create(['term_code' => '20251', 'grade' => 50, 'result' => KardexResult::Reprobada, 'attempt' => 1]);
    KardexRecord::factory()->for($this->student)->for($algebra)->create(['term_code' => '20253', 'grade' => null, 'result' => KardexResult::Cursando]);

    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
    app(SyncStudentAccounts::class)->handle(['24111234' => '4821']);
    $this->alumno = $this->student->fresh()->user;
});

describe('en la ficha del personal', function () {
    it('AC-15: «Resumen» es la primera pestaña y la de por omisión, en tercera persona y sin «Tu acceso»', function () {
        Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
            ->assertSet('tab', 'resumen')
            ->assertSeeHtml('data-testid="tab-resumen"')
            ->assertSeeHtml('data-testid="student-summary"')
            ->assertSeeHtml('data-testid="summary-tiles"')
            ->assertSee('Puede seleccionarla')
            ->assertSee('Su semestre')
            ->assertSee('Ya cumple lo que piden')
            ->assertDontSee('Tu acceso')
            ->assertDontSee('Cambiar contraseña')
            ->assertDontSeeHtml('data-testid="student-grid"');
    });

    it('AC-24: muestra los tres promedios con dos decimales', function () {
        Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
            ->assertSeeHtmlInOrder(['data-testid="average-general"', '90.00', 'data-testid="average-with-failed"', '70.00', 'data-testid="average-last-term"', '70.00'])
            ->assertSee('Ene-Jun 2025');
    });

    it('pone en «Atención» la materia reprobada en ordinario, en tercera persona', function () {
        Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
            ->assertSeeHtml('data-testid="attention-row"')
            ->assertSeeInOrder(['ACF-0903', 'Física General', 'Reprobada · prioridad', 'La reprobó en ordinario: tiene prioridad para seleccionarla.']);
    });

    it('los enlaces del tablero abren la pestaña de la retícula, el kárdex y la credencial', function () {
        Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
            ->dispatch('student-tab', tab: 'reticula')
            ->assertSet('tab', 'reticula')
            ->assertSeeHtml('data-testid="student-grid"')
            ->dispatch('student-tab', tab: 'otra')
            ->assertSet('tab', 'resumen');
    });

    it('«Puede seleccionarla» lista a lo más 6 materias, las reprobadas con prioridad primero, y enlaza a la retícula con el total', function () {
        foreach (range(1, 8) as $n) {
            ($this->subject)('SEL-000'.$n, 'Materia seleccionable '.$n);
        }

        $page = Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id]);

        // 8 nuevas + Física (reprobada en ordinario, ya puede cursarla) = 9; Álgebra se está cursando.
        $page->assertSeeHtml('data-testid="tile-selectable"')
            ->assertSee('Ver las 9 en la retícula')
            ->assertSeeInOrder(['ACF-0903', 'Física General', 'SEL-0001']);

        expect(substr_count($page->html(), 'data-testid="selectable-row"'))->toBe(6);
        $page->assertDontSee('Materia seleccionable 6');
    });

    it('con 6 o menos no ofrece «Ver las N»', function () {
        Livewire::actingAs($this->coordinator)->test('students::pages.student', ['student' => $this->student->id])
            ->assertSeeHtml('data-testid="selectable-row"')
            ->assertDontSeeHtml('data-testid="selectable-more"');
    });

    it('no la ve ni el coordinador de otra carrera ni la subdirección de otro campus, llamando el componente directo', function (Closure $user) {
        Livewire::actingAs($user($this))->test('students::components.student-summary', ['studentId' => $this->student->id])->assertForbidden();
    })->with([
        'el coordinador de otra carrera' => [fn ($test) => User::factory()->withRole(Role::Coordinador, ['campus_id' => $test->campus->id, 'career_id' => Career::factory()->create()->id])->create()],
        'la subdirección de otro campus' => [fn ($test) => User::factory()->withRole(Role::Subdireccion, ['campus_id' => Campus::factory()->create()->id])->create()],
        'otro alumno' => [fn ($test) => User::factory()->withRole(Role::Alumno)->create()],
    ]);

    it('la ficha de otra carrera sigue dando 403, con el tablero o sin él', function () {
        $other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create();

        $this->actingAs($other)->get(route('students.show', $this->student))->assertForbidden();
    });
});

describe('en el Resumen del alumno', function () {
    it('AC-15: el alumno ve el tablero en lugar de la portada mínima, con su acceso y sus promedios', function () {
        Livewire::actingAs($this->alumno)->test('core::pages.home')
            ->assertSeeHtml('data-testid="student-summary"')
            ->assertSeeHtml('data-testid="summary-identity"')
            ->assertSeeHtml('data-testid="summary-tiles"')
            ->assertSeeHtml('data-testid="summary-strip"')
            ->assertSeeHtml('data-testid="summary-averages"')
            ->assertSeeHtml('data-testid="summary-attention"')
            ->assertSeeHtml('data-testid="summary-selectable"')
            ->assertSeeHtml('data-testid="summary-credential"')
            ->assertSeeHtml('data-testid="summary-access"')
            ->assertSee('Daniela Ortiz')
            ->assertSee('Tu semestre')
            ->assertSee('La reprobaste en ordinario: tienes prioridad para seleccionarla.')
            ->assertSeeHtml('data-testid="average-general"')
            ->assertDontSeeHtml('data-testid="role-block"');
    });

    it('1.85: el encabezado del alumno lleva el subtítulo que Students registró en HomePanels', function () {
        Livewire::actingAs($this->alumno)->test('core::pages.home')
            ->assertSee('Tu avance, lo que cursas y lo que necesita tu atención.');
    });

    it('AC-15: «Puede seleccionarla» es visible para el alumno, que va a su retícula por «Mis datos»', function () {
        Livewire::actingAs($this->alumno)->test('students::components.student-summary')
            ->assertSee('Puede seleccionarla')
            ->assertSeeHtml(e(route('students.my-record', ['tab' => 'reticula'])));
    });

    it('el alumno solo ve lo suyo: sin ficha escolar no ve nada de otro, y no puede pedir el de otro', function () {
        Livewire::actingAs($this->alumno)->test('students::components.student-summary', ['studentId' => Student::factory()->create()->id])->assertForbidden();

        Livewire::actingAs(User::factory()->withRole(Role::Alumno)->create())->test('students::components.student-summary')
            ->assertSeeHtml('data-testid="summary-empty"')
            ->assertDontSee('Daniela Ortiz');
    });

    it('el personal no recibe el tablero en su Resumen y el admin tampoco lo abre como alumno', function () {
        Livewire::actingAs($this->coordinator)->test('core::pages.home')
            ->assertDontSeeHtml('data-testid="student-summary"')
            ->assertSeeHtml('data-testid="role-block"');

        Livewire::actingAs($this->coordinator)->test('students::components.student-summary')->assertForbidden();
    });
});
