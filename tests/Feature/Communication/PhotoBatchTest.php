<?php

use App\Modules\Communication\Actions\StorePhotoBatch;
use App\Modules\Communication\Enums\PhotoBatchKind;
use App\Modules\Communication\Enums\PhotoBatchStatus;
use App\Modules\Communication\Jobs\ProcessPhotoBatch;
use App\Modules\Communication\Models\PhotoBatch;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Lotes de fotos del personal en ZIP (CO-S5): `<usuario>.<ext>` sin distinguir mayúsculas, carpetas ignoradas y un
| reporte por archivo. La cola de las pruebas es `sync`: el lote se procesa al subirlo. Imágenes y nombres ficticios.
*/

beforeEach(function () {
    Storage::fake('imports');
    Storage::fake('staff_photos');
    $this->head = communicationHead();
});

/** Los bytes de una imagen ficticia. */
function batchImage(string $format = 'jpeg'): string
{
    $image = imagecreatetruecolor(300, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image);

    return (string) ob_get_clean();
}

/**
 * Un ZIP con esos archivos (nombre dentro del ZIP => bytes).
 *
 * @param  array<string, string>  $files
 */
function zipOf(array $files): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);

    foreach ($files as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }

    $zip->close();

    return UploadedFile::fake()->createWithContent('lote.zip', (string) file_get_contents($path));
}

it('aplica <usuario>.<ext> sin distinguir mayúsculas e ignora carpetas', function () {
    $ana = User::factory()->create(['username' => 'ANAA800101AAA']);
    $luis = User::factory()->create(['username' => 'LUIS750202BBB']);

    $batch = app(StorePhotoBatch::class)->handle(PhotoBatchKind::Personal, zipOf([
        'anaa800101aaa.jpg' => batchImage(),
        'fotos/nueva/LUIS750202BBB.PNG' => batchImage('png'),
        '__MACOSX/._anaa800101aaa.jpg' => 'basura',
        'carpeta/' => '',
    ]), $this->head);

    $batch->refresh();
    expect($batch->status)->toBe(PhotoBatchStatus::Terminado)
        ->and($batch->total)->toBe(2)
        ->and($batch->applied)->toBe(2)
        ->and(collect($batch->report)->pluck('outcome')->unique()->all())->toBe(['aplicada']);
    expect($ana->fresh()->photo_path)->not->toBeNull()
        ->and($luis->fresh()->photo_path)->not->toBeNull();
    Storage::disk('imports')->assertExists($batch->file_path);
});

it('reporta sin_persona, es_alumno, formato_invalido, muy_pesada y repetida', function () {
    $ana = User::factory()->create(['username' => 'ANAA800101AAA']);
    $student = User::factory()->withRole(Role::Alumno)->create(['username' => '24110001']);
    $other = User::factory()->create(['username' => 'OTRO800101CCC']);

    $batch = app(StorePhotoBatch::class)->handle(PhotoBatchKind::Personal, zipOf([
        'ANAA800101AAA.jpg' => batchImage(),
        'carpeta/anaa800101aaa.png' => batchImage('png'),
        'NADIE000000XXX.jpg' => batchImage(),
        '24110001.jpg' => batchImage(),
        'OTRO800101CCC.gif' => 'GIF89a',
        'OTRO800101CCC.pdf' => '%PDF',
    ]), $this->head);

    $outcomes = collect($batch->fresh()->report)->mapWithKeys(fn (array $row): array => [$row['file'] => $row['outcome']]);

    expect($outcomes['ANAA800101AAA.jpg'])->toBe('aplicada')
        ->and($outcomes['anaa800101aaa.png'])->toBe('repetida')
        ->and($outcomes['NADIE000000XXX.jpg'])->toBe('sin_persona')
        ->and($outcomes['24110001.jpg'])->toBe('es_alumno')
        ->and($outcomes['OTRO800101CCC.gif'])->toBe('formato_invalido');
    expect($student->fresh()->photo_path)->toBeNull()
        ->and($other->fresh()->photo_path)->toBeNull();
});

it('reporta muy_pesada y formato_invalido cuando el contenido no es una imagen', function () {
    User::factory()->create(['username' => 'ANAA800101AAA']);
    User::factory()->create(['username' => 'LUIS750202BBB']);

    $batch = app(StorePhotoBatch::class)->handle(PhotoBatchKind::Personal, zipOf([
        'ANAA800101AAA.jpg' => str_repeat('a', 11 * 1024 * 1024),
        'LUIS750202BBB.jpg' => 'no soy una imagen',
    ]), $this->head);

    $outcomes = collect($batch->fresh()->report)->pluck('outcome', 'key');

    expect($outcomes['ANAA800101AAA'])->toBe('muy_pesada')
        ->and($outcomes['LUIS750202BBB'])->toBe('formato_invalido');
});

it('un lote que falla queda fallido', function () {
    $batch = PhotoBatch::factory()->create(['uploaded_by' => $this->head->id]);
    Storage::disk('imports')->put($batch->file_path, 'esto no es un zip');

    try {
        ProcessPhotoBatch::dispatchSync($batch->id);
    } catch (Throwable) {
    }

    $batch->refresh();
    expect($batch->status)->toBe(PhotoBatchStatus::Fallido)
        ->and($batch->error)->not->toBeNull();
});

it('CO-06: un lote de alumnos se acepta y queda en cola como los del personal', function () {
    Queue::fake();

    $batch = app(StorePhotoBatch::class)->handle(PhotoBatchKind::Alumnos, zipOf(['21111182.jpg' => batchImage()]), $this->head);

    expect($batch->kind)->toBe(PhotoBatchKind::Alumnos)->and($batch->status)->toBe(PhotoBatchStatus::EnCola);
    Queue::assertPushed(ProcessPhotoBatch::class);
});

it('un archivo que no es ZIP se rechaza antes de guardarlo', function () {
    expect(fn () => app(StorePhotoBatch::class)->handle(PhotoBatchKind::Personal, UploadedFile::fake()->createWithContent('lote.zip', 'nada'), $this->head))
        ->toThrow(ValidationException::class);
    expect(PhotoBatch::query()->count())->toBe(0);
});

it('un archivo que no pasa el formato no ocupa la clave: ana.txt y luego ana.jpg aplica el jpg', function () {
    $ana = User::factory()->create(['username' => 'ANAA800101AAA']);

    $batch = app(StorePhotoBatch::class)->handle(PhotoBatchKind::Personal, zipOf([
        'ANAA800101AAA.txt' => 'notas',
        'ANAA800101AAA.jpg' => batchImage(),
    ]), $this->head);

    $outcomes = collect($batch->fresh()->report)->pluck('outcome', 'file');

    expect($outcomes['ANAA800101AAA.txt'])->toBe('formato_invalido')
        ->and($outcomes['ANAA800101AAA.jpg'])->toBe('aplicada');
    expect($ana->fresh()->photo_path)->not->toBeNull();
});

it('un ZIP con más de 2000 archivos se rechaza', function () {
    $files = [];
    for ($i = 0; $i <= 2000; $i++) {
        $files["f{$i}.jpg"] = '';
    }

    expect(fn () => app(StorePhotoBatch::class)->handle(PhotoBatchKind::Personal, zipOf($files), $this->head))
        ->toThrow(ValidationException::class, 'El ZIP trae más de 2000 archivos; divídelo en lotes más chicos.');
    expect(PhotoBatch::query()->count())->toBe(0);
});
