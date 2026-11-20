<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Livewire\Livewire;

/*
| «Mis datos» con pestañas (AC-15, supuesto por confirmar): «Mis datos», «Perfil» (retícula y kárdex del propio alumno,
| solo lectura) y «Credencial». Solo el alumno entra, y solo ve lo suyo.
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $department = Department::factory()->create();
    $this->career = Career::factory()->for($department)->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $plan = StudyPlan::factory()->for($this->career)->create(['code' => 'ISIC-2010-224', 'total_credits' => 260]);
    $calculus = Subject::factory()->create(['code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'credits' => 5]);
    $integral = Subject::factory()->create(['code' => 'ACF-0902', 'name' => 'Cálculo Integral', 'credits' => 5]);
    placeInPlan($plan, $calculus);
    placeInPlan($plan, $integral, 2);
    seriate($plan, $integral, $calculus);
    $attributes = ['career_id' => $this->career->id, 'study_plan_id' => $plan->id, 'campus_id' => $this->campus->id, 'current_semester' => 1];
    $this->student = Student::factory()->create(['control_number' => '21110001', 'name' => 'Ana López', ...$attributes]);
    $this->other = Student::factory()->create(['control_number' => '21110002', 'name' => 'Beto Ruiz', ...$attributes]);
    KardexRecord::factory()->for($this->student)->for($calculus)->create(['term_code' => '20253', 'grade' => 88]);
    KardexRecord::factory()->for($this->other)->for($calculus)->create(['term_code' => '20253', 'grade' => 61]);
    app(SyncStudentAccounts::class)->handle(['21110001' => '4821', '21110002' => '1357']);
    $this->user = $this->student->fresh()->user;
});

it('AC-15: por omisión muestra «Mis datos» y las otras pestañas no pintan su contenido', function () {
    Livewire::actingAs($this->user)->test('students::pages.my-record')
        ->assertSet('tab', 'datos')
        ->assertSee('Tu acceso')
        ->assertSeeHtml('data-testid="tab-perfil"')
        ->assertSeeHtml('data-testid="tab-credencial"')
        ->assertDontSeeHtml('data-testid="my-profile"')
        ->assertDontSeeHtml('data-testid="my-credential"');
});

it('AC-15: «Perfil» muestra su retícula y su kárdex, solo lectura y sin lo de otros', function () {
    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'perfil'])->test('students::pages.my-record')
        ->assertSeeHtml('data-testid="my-profile"')
        ->assertSeeInOrder(['ACF-0901', 'Cálculo Diferencial', 'Acreditada'])
        ->assertSeeInOrder(['ACF-0902', 'Cálculo Integral', 'Puede seleccionarla'])
        ->assertSeeInOrder(['20253', 'Ago-Dic 2025', 'ACF-0901', '88', 'Aprobada'])
        ->assertDontSeeHtml('>Puede seleccionar</span>')
        ->assertDontSee('Beto Ruiz')
        ->assertDontSee('21110002')
        ->assertDontSeeHtml('x-data="studentGrid"')
        ->assertDontSeeHtml('data-testid="chain-legend"')
        ->assertDontSeeHtml('x-on:click="toggleSubject')
        ->assertDontSeeHtml('aria-pressed');
});

it('AC-15: «Credencial» muestra nombre, número de control, carrera, campus, periodo activo e iniciales', function () {
    Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);

    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'credencial'])->test('students::pages.my-record')
        ->assertSeeHtml('data-testid="my-credential"')
        ->assertSee('Ana López')
        ->assertSee('21110001')
        ->assertSee('Ingeniería en Sistemas Computacionales')
        ->assertSee('Campus II')
        ->assertSee('Ene-Jun 2027')
        ->assertSeeInOrder(['data-testid="credential-photo"', 'AL'])
        ->assertDontSee('21110002');
});

it('AC-15: la ruta con ?tab=credencial abre la credencial y una pestaña inventada muestra «Mis datos»', function () {
    $this->actingAs($this->user)->get(route('students.my-record', ['tab' => 'credencial']))->assertOk()->assertSee('my-credential', false);
    $this->actingAs($this->user)->get(route('students.my-record', ['tab' => 'otra']))->assertOk()->assertSee('Tu acceso');
});

it('AC-15: nadie ve el perfil ni la credencial de otro alumno: ni por la ficha ni manipulando la URL', function () {
    $this->actingAs($this->user)->get(route('students.show', $this->other))->assertForbidden();

    $page = Livewire::actingAs($this->user)->withQueryParams(['tab' => 'perfil', 'student' => $this->other->id, 'student_id' => $this->other->id])->test('students::pages.my-record')
        ->assertSee('ACF-0901')
        ->assertDontSee('Beto Ruiz');
    $page->set('tab', 'credencial')->assertSee('21110001')->assertDontSee('21110002');
});

it('AC-15: el personal sin puesto de alumno no entra a ninguna pestaña de «Mis datos»', function (User $staff) {
    foreach (['datos', 'perfil', 'credencial'] as $tab) {
        $this->actingAs($staff)->get(route('students.my-record', ['tab' => $tab]))->assertForbidden();
    }
})->with([
    'docente' => fn (): User => User::factory()->withRole(Role::Docente)->create(),
    'coordinador' => fn (): User => User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => Career::factory()->create()->id])->create(),
]);

it('la ficha del personal sigue igual: pestañas Retícula y Kárdex, con resaltado, cadenas, «Puede seleccionar» y barra de selección', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();

    Livewire::actingAs($coordinator)->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeHtml('>Puede seleccionar</span>')
        ->assertSeeHtml('x-data="studentGrid"')
        ->assertSeeHtml('data-testid="chain-legend"')
        ->assertSeeHtml('aria-pressed="false"')
        ->assertSeeHtml('x-on:click="toggleSubject($el)"')
        ->assertSeeHtml('data-testid="tab-kardex"');

    Livewire::actingAs($coordinator)->withQueryParams(['tab' => 'kardex'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeHtml('data-testid="student-kardex"')
        ->assertSee('Aprobada');
});
