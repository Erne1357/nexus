<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Events\BatchUploaded;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| StoreImportBatch guarda el archivo que sube el admin en el disco `imports` (storage/app/imports,
| fuera de git: CLAUDE.md, regla dura 9) y registra el lote como `uploaded`. No lo lee: validar es
| otro paso (ValidateImportBatch), en cola. Quién puede subir lo decide la página con
| ImportBatchPolicy.
*/

class StoreBatchCampusScopedTemplate extends CampusesTemplate
{
    public function key(): string
    {
        return 'test-campus-scoped';
    }

    public function requiresCampus(): bool
    {
        return true;
    }
}

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class, StoreBatchCampusScopedTemplate::class]));
    $this->admin = User::factory()->admin()->create();
});

function storeImportBatch(User $user, string $template = 'test-campuses', ?UploadedFile $file = null, ?Campus $campus = null, ?ImportMapping $mapping = null): ImportBatch
{
    return app(StoreImportBatch::class)->handle(
        $user,
        $template,
        $file ?? UploadedFile::fake()->createWithContent('campus.csv', "code,name,modality\nC9,Campus Nueve,presencial\n"),
        $campus,
        $mapping,
    );
}

it('guarda el archivo en el disco de importaciones y registra el lote como subido', function () {
    $batch = storeImportBatch($this->admin);

    expect($batch->fresh())
        ->template->toBe('test-campuses')
        ->status->toBe(BatchStatus::Uploaded)
        ->original_name->toBe('campus.csv')
        ->file_path->toBe("{$batch->id}.csv")
        ->campus_id->toBeNull()
        ->mapping_id->toBeNull()
        ->total_rows->toBe(0)
        ->and($batch->uploader->is($this->admin))->toBeTrue();

    Storage::disk('imports')->assertExists($batch->file_path);
    expect(Storage::disk('imports')->get($batch->file_path))->toBe("code,name,modality\nC9,Campus Nueve,presencial\n");
});

it('guarda el campus del lote si la plantilla es de un campus, y lo ignora si no', function () {
    $campus = Campus::factory()->create();

    expect(storeImportBatch($this->admin, 'test-campus-scoped', campus: $campus)->campus->is($campus))->toBeTrue()
        ->and(storeImportBatch($this->admin, campus: $campus)->campus_id)->toBeNull();
});

it('guarda el mapeo de columnas elegido', function () {
    $mapping = ImportMapping::factory()->create(['template' => 'test-campuses']);

    expect(storeImportBatch($this->admin, mapping: $mapping)->mapping->is($mapping))->toBeTrue();
});

it('acepta xlsx y csv sin importar mayúsculas en la extensión', function (string $name) {
    $batch = storeImportBatch($this->admin, file: UploadedFile::fake()->createWithContent($name, 'x'));

    Storage::disk('imports')->assertExists($batch->file_path);
})->with(['CAMPUS.CSV', 'campus.xlsx', 'campus.txt']);

it('registra en la bitácora quién subió qué archivo y para qué plantilla', function () {
    $batch = storeImportBatch($this->admin);

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('created')
        ->description->toBe('Subió campus.csv para importar Campus (prueba)')
        ->and($entry->subject->is($batch))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue();
});

it('avisa con BatchUploaded solo después del commit', function () {
    $heard = [];
    Event::listen(BatchUploaded::class, function (BatchUploaded $event) use (&$heard): void {
        $heard[] = $event->batch->original_name;
    });

    try {
        DB::transaction(function (): void {
            storeImportBatch($this->admin);

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([])
        ->and(ImportBatch::count())->toBe(0);

    storeImportBatch($this->admin);

    expect($heard)->toBe(['campus.csv']);
});

it('rechaza sin guardar nada lo que no se puede importar', function (Closure $attempt, string $field, string $message) {
    try {
        $attempt($this->admin);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe([$field => [$message]])
            ->and(ImportBatch::count())->toBe(0)
            ->and(Activity::count())->toBe(0)
            ->and(Storage::disk('imports')->allFiles())->toBe([]);

        return;
    }

    $this->fail('Se esperaba ValidationException.');
})->with([
    'plantilla desconocida' => [
        fn (User $admin) => storeImportBatch($admin, 'kardex'),
        'template', 'Elige una plantilla de importación.',
    ],
    'Excel antiguo' => [
        fn (User $admin) => storeImportBatch($admin, file: UploadedFile::fake()->createWithContent('campus.xls', 'x')),
        'file', 'El archivo debe ser .xlsx o .csv.',
    ],
    'plantilla de campus sin campus' => [
        fn (User $admin) => storeImportBatch($admin, 'test-campus-scoped'),
        'campus', 'Elige en la barra superior el campus al que pertenece el archivo.',
    ],
    'mapeo de otra plantilla' => [
        fn (User $admin) => storeImportBatch($admin, mapping: ImportMapping::factory()->create(['template' => 'test-campus-scoped'])),
        'mapping', 'El mapeo de columnas es de otra plantilla.',
    ],
]);
