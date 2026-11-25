<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| «Mis datos» con pestañas (AC-15, supuesto por confirmar): «Mis datos», «Retícula» y «Kárdex» (del propio alumno,
| solo lectura, con las cifras arriba; 1.42) y «Credencial». Solo el alumno entra, y solo ve lo suyo.
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
        ->assertSeeHtml('data-testid="tab-reticula"')
        ->assertSeeHtml('data-testid="tab-kardex"')
        ->assertDontSeeHtml('data-testid="tab-perfil"')
        ->assertSeeHtml('data-testid="tab-credencial"')
        ->assertDontSeeHtml('data-testid="my-reticula"')
        ->assertDontSeeHtml('data-testid="my-kardex"')
        ->assertDontSeeHtml('data-testid="my-credential"');
});

it('AC-15: «Retícula» muestra su retícula con las cifras arriba, solo lectura, y sin lo de otros ni el kárdex', function () {
    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'reticula'])->test('students::pages.my-record')
        ->assertSeeHtml('data-testid="my-reticula"')
        ->assertDontSeeHtml('data-testid="my-kardex"')
        ->assertSeeInOrder(['Créditos aprobados', 'Materias acreditadas', 'Cursando', 'ACF-0901', 'Cálculo Diferencial', 'Acreditada'])
        ->assertSeeInOrder(['ACF-0902', 'Cálculo Integral', 'Puede seleccionarla'])
        ->assertDontSee('Cada materia que has cursado')
        ->assertDontSeeHtml('>Puede seleccionar</span>')
        ->assertDontSee('Beto Ruiz')
        ->assertDontSee('21110002')
        ->assertDontSeeHtml('x-data="studentGrid"')
        ->assertDontSeeHtml('data-testid="chain-legend"')
        ->assertDontSeeHtml('x-on:click="toggleSubject')
        ->assertDontSeeHtml('aria-pressed');
});

it('AC-15: «Kárdex» muestra su kárdex con las cifras arriba, sin la retícula y sin lo de otros', function () {
    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'kardex'])->test('students::pages.my-record')
        ->assertSeeHtml('data-testid="my-kardex"')
        ->assertDontSeeHtml('data-testid="my-reticula"')
        ->assertSeeInOrder(['Créditos aprobados', 'Materias acreditadas', 'Cursando', '20253', 'Ago-Dic 2025', 'ACF-0901', '88', 'Aprobada'])
        ->assertDontSee('Puede seleccionarla')
        ->assertDontSee('Beto Ruiz')
        ->assertDontSee('21110002');
});

it('AC-15: ?tab=perfil, de los enlaces viejos, abre «Retícula»', function () {
    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'perfil'])->test('students::pages.my-record')
        ->assertSet('tab', 'reticula')
        ->assertSeeHtml('data-testid="my-reticula"');
    $this->actingAs($this->user)->get(route('students.my-record', ['tab' => 'perfil']))->assertOk()->assertSee('my-reticula', false);
});

it('AC-15: la consulta de la retícula solo corre en «Retícula» y «Kárdex», no en «Mis datos» ni en «Credencial»', function () {
    $kardexQueries = function (string $tab): int {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, 'kardex_records')) {
                $count++;
            }
        });
        Livewire::actingAs($this->user)->withQueryParams(['tab' => $tab])->test('students::pages.my-record')->assertOk();

        return $count;
    };

    expect($kardexQueries('datos'))->toBe(0)
        ->and($kardexQueries('credencial'))->toBe(0)
        ->and($kardexQueries('reticula'))->toBeGreaterThan(0)
        ->and($kardexQueries('kardex'))->toBeGreaterThan(0);
});

it('AC-15: «Credencial» muestra la plantilla del ITCJ con nombre / número de control, carrera / estatus y la institución', function () {
    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'credencial'])->test('students::pages.my-record')
        ->assertSeeHtml('data-testid="my-credential"')
        ->assertSeeHtml('data-testid="credential-front"')
        ->assertSeeHtml('data-testid="credential-back"')
        ->assertSee('Ana López / 21110001')
        ->assertSee('Ing. Sistemas Computacionales / Estudiante')
        ->assertSee('Instituto Tecnológico de Ciudad Juárez')
        ->assertSeeInOrder(['data-testid="credential-photo"', 'AL'])
        ->assertDontSee('21110002');
});

it('AC-15: la ruta con ?tab=credencial abre la credencial y una pestaña inventada muestra «Mis datos»', function () {
    $this->actingAs($this->user)->get(route('students.my-record', ['tab' => 'credencial']))->assertOk()->assertSee('my-credential', false);
    $this->actingAs($this->user)->get(route('students.my-record', ['tab' => 'otra']))->assertOk()->assertSee('Tu acceso');
});

it('AC-15: nadie ve la retícula, el kárdex ni la credencial de otro alumno: ni por la ficha ni manipulando la URL', function () {
    $this->actingAs($this->user)->get(route('students.show', $this->other))->assertForbidden();

    $page = Livewire::actingAs($this->user)->withQueryParams(['tab' => 'reticula', 'student' => $this->other->id, 'student_id' => $this->other->id])->test('students::pages.my-record')
        ->assertSee('ACF-0901')
        ->assertDontSee('Beto Ruiz');
    $page->set('tab', 'credencial')->assertSee('21110001')->assertDontSee('21110002');
});

it('AC-15: el personal sin puesto de alumno no entra a ninguna pestaña de «Mis datos»', function (User $staff) {
    foreach (['datos', 'reticula', 'kardex', 'credencial'] as $tab) {
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

it('AC-15: una pestaña con formato inválido en la URL (?tab[]=x) cae en «Mis datos» con 200', function () {
    $this->actingAs($this->user)->get('/my-record?tab[]=x')->assertOk()->assertSee('Tu acceso');
});

it('AC-15: el alumno sin fila de alumno ve un vacío claro en Retícula, Kárdex y Credencial', function (string $tab) {
    $user = User::factory()->withRole(Role::Alumno)->create();

    Livewire::actingAs($user)->withQueryParams(['tab' => $tab])->test('students::pages.my-record')
        ->assertOk()
        ->assertSee('Todavía no encontramos tus datos escolares.')
        ->assertDontSeeHtml('data-testid="my-reticula"')
        ->assertDontSeeHtml('data-testid="my-kardex"')
        ->assertDontSeeHtml('data-testid="my-credential"');
})->with(['reticula', 'kardex', 'credencial']);

it('AC-15: la retícula y el kárdex hablan de tú y la ficha del personal conserva su tercera persona', function () {
    KardexRecord::query()->delete();
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();

    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'kardex'])->test('students::pages.my-record')
        ->assertSee('Tu kárdex todavía no se importa.')
        ->assertSee('Cada materia que has cursado')
        ->assertDontSee('El kárdex de este alumno');

    Livewire::actingAs($coordinator)->withQueryParams(['tab' => 'kardex'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSee('El kárdex de este alumno todavía no se importa.')
        ->assertSee('Cada materia que ha cursado');
});

it('AC-15: las iniciales de la credencial salen del nombre del alumno que se muestra', function () {
    $this->student->forceFill(['name' => 'María Zamora'])->save();

    Livewire::actingAs($this->user)->withQueryParams(['tab' => 'credencial'])->test('students::pages.my-record')
        ->assertSee('María Zamora')
        ->assertSeeInOrder(['data-testid="credential-photo"', 'MZ']);
});

it('la ficha del personal con ?tab[]=x responde 200 y muestra la retícula', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();

    $this->actingAs($coordinator)->get(route('students.show', $this->student).'?tab[]=x')->assertOk()->assertSee('ACF-0901');
});
