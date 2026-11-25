<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Communication\Actions\StorePhotoBatch;
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

it('CO-06: un archivo con formato inválido no tapa a la foto del mismo número de control', function () {
    $fake = studentImage();
    $zipPath = tempnam(sys_get_temp_dir(), 'nxz');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('21111182.txt', 'no soy foto');
    $zip->addFromString('21111182.jpg', file_get_contents($fake->getRealPath()));
    $zip->close();
    Storage::disk('imports')->put('photo-batches/lote2.zip', file_get_contents($zipPath));
    unlink($zipPath);

    $batch = PhotoBatch::query()->create([
        'kind' => PhotoBatchKind::Alumnos, 'status' => PhotoBatchStatus::EnCola, 'file_path' => 'photo-batches/lote2.zip',
        'original_name' => 'lote2.zip', 'uploaded_by' => $this->by->id,
    ]);

    app()->call([new ProcessPhotoBatch($batch->id), 'handle']);

    expect(collect($batch->fresh()->report)->pluck('outcome', 'file')->all())->toBe([
        '21111182.txt' => PhotoOutcome::FormatoInvalido->value,
        '21111182.jpg' => PhotoOutcome::Aplicada->value,
    ]);
});

it('CO-06: un ZIP de alumnos con más de 2000 archivos se rechaza', function () {
    $zip = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'nxz');
    $zip->open($path, ZipArchive::OVERWRITE);
    for ($i = 0; $i <= StorePhotoBatch::MAX_ENTRIES; $i++) {
        $zip->addFromString("a{$i}.jpg", 'x');
    }
    $zip->close();

    expect(fn () => app(StorePhotoBatch::class)->handle(PhotoBatchKind::Alumnos, UploadedFile::fake()->createWithContent('g.zip', file_get_contents($path)), $this->by))
        ->toThrow(ValidationException::class);
    unlink($path);
});

it('una URL firmada de la foto del alumno alterada da 403', function () {
    app(SetStudentPhoto::class)->handle($this->student, studentImage(), $this->by);
    $url = StudentPhotoUrl::for($this->student->fresh());

    $this->actingAs($this->by)->get($url.'x')->assertForbidden();
    $this->actingAs($this->by)->get(preg_replace('/version\/?/', '', $url).'&extra=1')->assertForbidden();
});

it('CO-06: la foto del alumno sale con caché privada de 2 horas y nosniff', function () {
    app(SetStudentPhoto::class)->handle($this->student, studentImage(), $this->by);

    $response = $this->actingAs($this->by)->get(StudentPhotoUrl::for($this->student->fresh()))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=7200')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

function studentZipBatch(array $files, string $name): PhotoBatch
{
    $path = tempnam(sys_get_temp_dir(), 'nxz');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $file => $bytes) {
        $zip->addFromString($file, $bytes);
    }
    $zip->close();
    Storage::disk('imports')->put("photo-batches/{$name}.zip", file_get_contents($path));
    unlink($path);

    return PhotoBatch::query()->create([
        'kind' => PhotoBatchKind::Alumnos, 'status' => PhotoBatchStatus::EnCola, 'file_path' => "photo-batches/{$name}.zip",
        'original_name' => "{$name}.zip", 'uploaded_by' => test()->by->id,
    ]);
}

it('CO-06: un .jpg que no es imagen en el lote de alumnos es formato inválido', function () {
    $batch = studentZipBatch(['21111182.jpg' => '%PDF-1.4 no soy imagen'], 'malo');

    app()->call([new ProcessPhotoBatch($batch->id), 'handle']);

    expect(collect($batch->fresh()->report)->pluck('outcome', 'file')->all())->toBe(['21111182.jpg' => PhotoOutcome::FormatoInvalido->value]);
});

it('CO-06: un número de control sin año legible, aunque exista, queda sin persona', function () {
    Student::factory()->create(['control_number' => 'XYZ']);
    $fake = studentImage();
    $batch = studentZipBatch(['XYZ.jpg' => file_get_contents($fake->getRealPath())], 'sinanio');

    app()->call([new ProcessPhotoBatch($batch->id), 'handle']);

    expect(collect($batch->fresh()->report)->pluck('outcome', 'file')->all())->toBe(['XYZ.jpg' => PhotoOutcome::SinPersona->value]);
});
