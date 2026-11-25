<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| «Fotos de alumnos» (`/comunicacion/fotos-alumnos`, communication::pages.student-photos). CO-06 (C1): Comunicación sube
| la foto de un alumno, buscándolo por número de control o nombre; nadie más entra. Alumnos e imágenes ficticios.
*/

beforeEach(function () {
    Storage::fake('student_photos');
    Storage::fake('imports');
    $campus = Campus::factory()->create(['code' => 'C1']);
    $career = Career::factory()->for(Department::factory())->create();
    $plan = StudyPlan::factory()->for($career)->create();
    $attributes = ['career_id' => $career->id, 'study_plan_id' => $plan->id, 'campus_id' => $campus->id];
    $this->ana = Student::factory()->create(['control_number' => '21111182', 'name' => 'Ana Ficticia', 'status' => StudentStatus::Activo, ...$attributes]);
    $this->beto = Student::factory()->create(['control_number' => '22110005', 'name' => 'Beto Inventado', 'status' => StudentStatus::BajaTemporal, ...$attributes]);
    $this->head = communicationHead();
});

it('CO-06: Comunicación sube la foto de un alumno desde el drawer', function () {
    Livewire::actingAs($this->head)->test('communication::pages.student-photos')
        ->assertOk()
        ->assertSee('Ana Ficticia')
        ->call('openPhoto', $this->ana->id)
        ->set('photo', UploadedFile::fake()->image('ana.jpg', 700, 900))
        ->assertSeeHtml('data-testid="photo-preview"')
        ->call('savePhoto')
        ->assertHasNoErrors()
        ->assertSet('showPhoto', false);

    expect(Storage::disk('student_photos')->exists('2021/21111182.jpg'))->toBeTrue();
});

it('CO-06: busca por número de control o nombre y filtra por foto y por vigentes', function () {
    Storage::disk('student_photos')->put('2021/21111182.jpg', 'x');

    Livewire::actingAs($this->head)->test('communication::pages.student-photos')
        ->set('search', '22110005')->assertSee('Beto Inventado')->assertDontSee('Ana Ficticia')
        ->set('search', '')->set('photoFilter', 'con')->assertSee('Ana Ficticia')->assertDontSee('Beto Inventado')
        ->set('photoFilter', 'sin')->assertSee('Beto Inventado')->assertDontSee('Ana Ficticia')
        ->set('photoFilter', '')->set('onlyCurrent', true)->assertSee('Ana Ficticia')->assertDontSee('Beto Inventado');
});

it('CO-06: el drawer del lote en ZIP acepta un ZIP de alumnos', function () {
    Queue::fake();
    $zip = UploadedFile::fake()->createWithContent('lote.zip', (function () {
        $path = tempnam(sys_get_temp_dir(), 'nxz');
        $z = new ZipArchive;
        $z->open($path, ZipArchive::OVERWRITE);
        $z->addFromString('21111182.jpg', 'x');
        $z->close();

        return file_get_contents($path);
    })());

    Livewire::actingAs($this->head)->test('communication::pages.student-photos')
        ->call('openZip')->set('zip', $zip)->call('uploadZip')->assertHasNoErrors();

    expect(PhotoBatch::query()->where('kind', 'alumnos')->count())->toBe(1);
});

it('CO-06: quien no es de Comunicación no entra a Fotos de alumnos', function () {
    $coordinator = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->ana->campus_id, 'career_id' => $this->ana->career_id])->create();

    Livewire::actingAs($coordinator)->test('communication::pages.student-photos')->assertForbidden();
    Livewire::actingAs(communicationStaff())->test('communication::pages.student-photos')->assertOk();
    $this->actingAs($coordinator)->get(route('communication.student-photos'))->assertForbidden();
});
