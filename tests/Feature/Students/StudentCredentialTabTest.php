<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Enums\CredentialRequestStatus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Pestaña «Credencial» de la ficha del alumno (CO-10, C13, C14): el frente con la foto y el reverso sin el QR vivo, para
| quien ya ve la ficha; «Cambiar foto» solo con `students.photo.manage`. Alumnos e imágenes ficticios.
*/

beforeEach(function () {
    Storage::fake('student_photos');
    $this->campus = Campus::factory()->create(['code' => 'C1']);
    $this->career = Career::factory()->for(Department::factory())->create(['code' => 'ISC']);
    $plan = StudyPlan::factory()->for($this->career)->create();
    $this->student = Student::factory()->create(['control_number' => '21111182', 'name' => 'Ana Ficticia', 'status' => StudentStatus::Activo, 'career_id' => $this->career->id, 'study_plan_id' => $plan->id, 'campus_id' => $this->campus->id]);
    $this->coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => $this->career->id])->create();
});

it('CO-10: la ficha del alumno muestra la pestaña Credencial con el frente y el reverso sin QR a quien tiene students.student.view', function () {
    app(SetStudentPhoto::class)->handle($this->student, UploadedFile::fake()->image('f.jpg', 700, 900), communicationStaff());

    Livewire::actingAs($this->coordinator)->withQueryParams(['tab' => 'credencial'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeHtml('data-testid="credential-front"')
        ->assertSeeHtml('data-testid="credential-back"')
        ->assertSeeHtml('data-testid="credential-photo-img"')
        ->assertSee('El código QR solo lo genera el alumno desde su cuenta.')
        ->assertDontSeeHtml('data-qr-url')
        ->assertSeeHtml('data-testid="credential-history"');
});

it('CO-10: un coordinador de otra carrera no ve la ficha ni la foto', function () {
    $other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->campus->id, 'career_id' => Career::factory()->create()->id])->create();

    Livewire::actingAs($other)->test('students::pages.student', ['student' => $this->student->id])->assertForbidden();
});

it('CO-10: Cambiar foto solo aparece con students.photo.manage', function () {
    Livewire::actingAs($this->coordinator)->withQueryParams(['tab' => 'credencial'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertDontSee('Cambiar foto')
        ->assertDontSee('Subir foto');

    $manager = User::factory()->withRole(Role::Admin)->create();
    Livewire::actingAs($manager)->withQueryParams(['tab' => 'credencial'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSee('Subir foto')
        ->call('openPhoto')
        ->set('photo', UploadedFile::fake()->image('f.jpg', 700, 900))
        ->call('savePhoto')
        ->assertHasNoErrors();

    expect(Storage::disk('student_photos')->exists('2021/21111182.jpg'))->toBeTrue();
});

it('CO-10: quien no tiene students.photo.manage no cambia la foto aunque llame el método', function () {
    Livewire::actingAs($this->coordinator)->withQueryParams(['tab' => 'credencial'])->test('students::pages.student', ['student' => $this->student->id])
        ->call('openPhoto')
        ->assertForbidden();
});

it('CO-10: la pestaña Credencial de la ficha muestra el historial de solicitudes del alumno', function () {
    CredentialRequest::factory()->create(['student_id' => $this->student->id, 'status' => CredentialRequestStatus::Rechazada, 'rejection_reason' => 'Falta comprobante']);

    Livewire::actingAs($this->coordinator)->withQueryParams(['tab' => 'credencial'])->test('students::pages.student', ['student' => $this->student->id])
        ->assertSeeHtml('data-testid="credential-requests-history"')
        ->assertSee('Falta comprobante');
});
