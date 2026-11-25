<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Communication\Enums\PhotoOutcome;
use App\Modules\Communication\Jobs\ProcessPhotoBatch;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Students\Actions\SetStudentPhoto;
use App\Modules\Students\Events\StudentPhotoChanged;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Support\StudentPhotos;
use App\Modules\Students\Support\StudentPhotoUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Fotos de los alumnos (CO-06, C1): guardar una foto mueve la anterior a `_anteriores` sin borrarla, el lote en ZIP
| (`<número de control>.<ext>`) y la URL firmada de 2 horas. Alumnos e imágenes ficticios.
*/

beforeEach(function () {
    Storage::fake('student_photos');
    Storage::fake('imports');
    $campus = Campus::factory()->create(['code' => 'C1']);
    $career = Career::factory()->for(Department::factory())->create();
    $plan = StudyPlan::factory()->for($career)->create();
    $this->student = Student::factory()->create(['control_number' => '21111182', 'name' => 'Ana Ficticia', 'career_id' => $career->id, 'study_plan_id' => $plan->id, 'campus_id' => $campus->id]);
    $this->by = communicationStaff();
});

function studentImage(string $name = 'f.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 700, 900);
}

it('CO-06: guardar la foto de un alumno mueve la anterior a _anteriores y la credencial lee la nueva', function () {
    Storage::disk('student_photos')->put('2021/21111182.png', 'foto-vieja');
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(10, 30, 15));

    Event::fake([StudentPhotoChanged::class]);
    app(SetStudentPhoto::class)->handle($this->student, studentImage(), $this->by);

    $disk = Storage::disk('student_photos');
    expect($disk->exists('2021/21111182.jpg'))->toBeTrue()
        ->and($disk->exists('2021/21111182.png'))->toBeFalse()
        ->and($disk->get('_anteriores/2021/21111182-20261124103015.png'))->toBe('foto-vieja')
        ->and(app(StudentPhotos::class)->locate('21111182'))->toBe('2021/21111182.jpg')
        ->and(app(StudentPhotos::class)->read('21111182')['mime'])->toBe('image/jpeg');

    Event::assertDispatched(StudentPhotoChanged::class, fn (StudentPhotoChanged $event): bool => $event->studentId === $this->student->id);

    $this->assertDatabaseHas('activity_log', ['subject_id' => $this->student->id, 'description' => 'Cambió la foto de la credencial']);
});

it('CO-06: una segunda foto no borra la primera', function () {
    app(SetStudentPhoto::class)->handle($this->student, studentImage(), $this->by);
    $this->travel(2)->seconds();
    app(SetStudentPhoto::class)->handle($this->student, studentImage(), $this->by);

    expect(Storage::disk('student_photos')->allFiles('_anteriores/2021'))->toHaveCount(1)
        ->and(Storage::disk('student_photos')->exists('2021/21111182.jpg'))->toBeTrue();
});

it('CO-06: un alumno sin año de ingreso legible no recibe foto', function () {
    $student = Student::factory()->create(['control_number' => 'XYZ']);

    expect(fn () => app(SetStudentPhoto::class)->handle($student, studentImage(), $this->by))
        ->toThrow(ValidationException::class);
});

it('CO-06: el lote de alumnos aplica <número de control>.<ext> y reporta los que no existen', function () {
    Student::factory()->create(['control_number' => '22110005']);
    $zipPath = tempnam(sys_get_temp_dir(), 'nxz');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $fake = studentImage();
    $image = file_get_contents($fake->getRealPath());
    $zip->addFromString('21111182.jpg', $image);
    $zip->addFromString('carpeta/22110005.JPG', $image);
    $zip->addFromString('99999999.jpg', $image);
    $zip->addFromString('22110005.png', $image);
    $zip->addFromString('23110001.txt', 'no soy foto');
    $zip->close();
    Storage::disk('imports')->put('photo-batches/lote.zip', file_get_contents($zipPath));
    unlink($zipPath);

    $batch = PhotoBatch::query()->create([
        'kind' => PhotoBatchKind::Alumnos, 'status' => PhotoBatchStatus::EnCola, 'file_path' => 'photo-batches/lote.zip',
        'original_name' => 'lote.zip', 'uploaded_by' => $this->by->id,
    ]);

    app()->call([new ProcessPhotoBatch($batch->id), 'handle']);

    $outcomes = collect($batch->fresh()->report)->pluck('outcome', 'file')->all();
    expect($outcomes)->toBe([
        '21111182.jpg' => PhotoOutcome::Aplicada->value,
        '22110005.JPG' => PhotoOutcome::Aplicada->value,
        '99999999.jpg' => PhotoOutcome::SinPersona->value,
        '22110005.png' => PhotoOutcome::Repetida->value,
        '23110001.txt' => PhotoOutcome::FormatoInvalido->value,
    ])
        ->and($batch->fresh()->applied)->toBe(2)
        ->and(app(StudentPhotos::class)->locate('21111182'))->toBe('2021/21111182.jpg')
        ->and(app(StudentPhotos::class)->locate('22110005'))->toBe('2022/22110005.jpg');
});

it('la URL firmada de la foto del alumno sirve a quien la pide y vencida da 403', function () {
    app(SetStudentPhoto::class)->handle($this->student, studentImage(), $this->by);
    $url = StudentPhotoUrl::for($this->student->fresh());

    $this->actingAs($this->by)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $this->travel(3)->hours();
    $this->actingAs($this->by)->get($url)->assertForbidden();
});

it('CO-10: un coordinador de otra carrera no ve la foto del alumno', function () {
    app(SetStudentPhoto::class)->handle($this->student, studentImage(), $this->by);
    $url = StudentPhotoUrl::for($this->student->fresh());
    $other = User::factory()->withRole(Role::Coordinador, ['campus_id' => $this->student->campus_id, 'career_id' => Career::factory()->create()->id])->create();

    $this->actingAs($other)->get($url)->assertForbidden();
});
