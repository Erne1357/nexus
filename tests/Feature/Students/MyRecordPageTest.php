<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SyncStudentAccounts;
use App\Modules\Students\Models\Student;
use Livewire\Livewire;

/*
| «Mis datos» (AC-15, supuesto por confirmar): la portada mínima del alumno mientras no haya pantallas de alumno. Solo
| el alumno la ve, y solo con lo suyo.
*/

beforeEach(function () {
    config(['nexus.institutional_email_domain' => 'cdjuarez.tecnm.mx']);
    $career = Career::factory()->create(['code' => 'ISC', 'name' => 'Ingeniería en Sistemas Computacionales']);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id, 'code' => 'ISIC-2010-224']);
    $campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $attributes = ['career_id' => $career->id, 'study_plan_id' => $plan->id, 'campus_id' => $campus->id, 'current_semester' => 5];
    $this->student = Student::factory()->create(['control_number' => '21110001', 'name' => 'Ana López', ...$attributes]);
    $this->other = Student::factory()->create(['control_number' => '21110002', 'name' => 'Beto Ruiz', ...$attributes]);
    app(SyncStudentAccounts::class)->handle(['21110001' => '4821', '21110002' => '1357']);
    $this->user = $this->student->fresh()->user;
});

it('AC-15: el alumno ve sus datos escolares y su acceso, con el enlace para cambiar su contraseña', function () {
    Livewire::actingAs($this->user)->test('students::pages.my-record')
        ->assertOk()
        ->assertSee('Ana López')
        ->assertSee('21110001')
        ->assertSee('Ingeniería en Sistemas Computacionales')
        ->assertSee('ISIC-2010-224')
        ->assertSee('Campus II')
        ->assertSee('Activo')
        ->assertSee('l21110001@cdjuarez.tecnm.mx')
        ->assertSee('Entras con tu NIP del SII')
        ->assertSee(route('user-password.edit'))
        ->assertDontSee('Beto Ruiz')
        ->assertDontSee('21110002');
});

it('AC-15: al cambiar su NIP la portada ya no dice que entra con él', function () {
    $this->user->forceFill(['password_origin' => PasswordOrigin::User])->save();

    Livewire::actingAs($this->user)->test('students::pages.my-record')
        ->assertDontSee('Entras con tu NIP del SII')
        ->assertSee('Ya no entras con tu NIP del SII');
});

it('AC-15: «Mis datos» aparece en el menú del alumno, que no ve el calendario del periodo en Inicio', function () {
    Term::factory()->current()->create();

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('Mis datos')
        ->assertSee(route('students.my-record'))
        ->assertSee(Role::Alumno->description())
        ->assertDontSee(route('students.index'));

    $this->get(route('students.my-record'))->assertOk()->assertSee('Ana López');
});

it('AC-15: nadie más entra a «Mis datos» ni lo ve en el menú: ni el personal ni el admin', function (User $staff) {
    $this->actingAs($staff)->get(route('students.my-record'))->assertForbidden();
    $this->actingAs($staff)->get('/')->assertDontSee(route('students.my-record'));
})->with([
    'docente' => fn (): User => User::factory()->withRole(Role::Docente)->create(),
    'coordinador' => fn (): User => User::factory()->withRole(Role::Coordinador, ['campus_id' => Campus::factory()->create()->id, 'career_id' => Career::factory()->create()->id])->create(),
    'admin' => fn (): User => User::factory()->admin()->create(),
]);

it('AC-15: el alumno no entra a la lista de alumnos ni a la ficha de otro alumno', function () {
    $this->actingAs($this->user)->get(route('students.index'))->assertForbidden();
    $this->actingAs($this->user)->get(route('students.show', $this->other))->assertForbidden();
});

it('AC-15: sin sesión, «Mis datos» manda al login', function () {
    $this->get(route('students.my-record'))->assertRedirect(route('login'));
});
