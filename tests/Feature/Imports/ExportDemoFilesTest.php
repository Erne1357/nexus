<?php

use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| `imports:export-demo`: escribe lo sembrado como un CSV por plantilla, con encabezados que el asistente
| reconoce sin mapeo, para probar la importación con archivos grandes sin exportaciones reales del SII.
*/

const DEMO_TEMPLATES = ['departments', 'careers', 'study_plans', 'subjects', 'prerequisites', 'students', 'kardex'];

beforeEach(function () {
    Storage::fake('imports');
    $this->seed(DemoSeeder::class);
});

it('escribe un archivo por plantilla en la carpeta pedida', function () {
    $this->artisan('imports:export-demo', ['--dir' => 'demo-prueba'])->assertSuccessful();

    foreach (DEMO_TEMPLATES as $template) {
        Storage::disk('imports')->assertExists("demo-prueba/{$template}.csv");
    }
});

it('cada archivo pasa por su plantilla sin una sola fila con error', function () {
    $this->artisan('imports:export-demo')->assertSuccessful();
    $admin = User::factory()->admin()->create();

    foreach (DEMO_TEMPLATES as $template) {
        $batch = ImportBatch::factory()->create(['template' => $template, 'uploaded_by' => $admin->id]);
        $batch->update(['file_path' => "{$batch->id}.csv"]);
        Storage::disk('imports')->copy("demo/{$template}.csv", $batch->file_path);

        $batch = app(ValidateImportBatch::class)->handle($batch);

        expect([$template, $batch->status->value, $batch->error_rows, $batch->total_rows > 0])->toBe([$template, 'validated', 0, true]);
    }
});

/**
 * @return list<list<string|null>>
 */
function demoCsv(string $template): array
{
    $stream = fopen(Storage::disk('imports')->path("demo/{$template}.csv"), 'r');
    $rows = [];

    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

it('trae un renglón por carrera y campus, sin borrados y con lo inactivo', function () {
    $this->artisan('imports:export-demo')->assertSuccessful();

    $offerings = DB::table('career_campus as cc')->join('careers as c', 'c.id', '=', 'cc.career_id')
        ->whereNull('cc.deleted_at')->whereNull('c.deleted_at')->count();
    $careers = array_slice(demoCsv('careers'), 1);

    expect($careers)->toHaveCount($offerings)
        ->and(collect($careers)->map(fn (array $r): string => "{$r[0]}/{$r[3]}")->unique())->toHaveCount($offerings)
        ->and(collect(demoCsv('study_plans'))->pluck(4))->toContain('No')
        ->and(demoCsv('students'))->toHaveCount(DB::table('students')->whereNull('deleted_at')->count() + 1)
        ->and(demoCsv('kardex'))->toHaveCount(DB::table('kardex_records')->whereNull('deleted_at')->count() + 1);
});

it('rechaza una carpeta vacía o que sale del disco', function (string $dir) {
    $this->artisan('imports:export-demo', ['--dir' => $dir])->assertFailed();

    expect(Storage::disk('imports')->allFiles())->toBe([]);
})->with(['../x', '']);

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('imports:export-demo')->assertFailed();

    expect(Storage::disk('imports')->allFiles())->toBe([]);
});

it('avisa y falla si no puede abrir un archivo', function () {
    $this->artisan('imports:export-demo');
    $path = Storage::disk('imports')->path('demo/departments.csv');
    chmod($path, 0444);

    if (is_writable($path)) {
        $this->markTestSkipped('Corre como root: el archivo de solo lectura sí se puede escribir.');
    }

    $this->artisan('imports:export-demo')->expectsOutputToContain('No se pudo escribir demo/departments.csv')->assertFailed();
});

it('lo exportado se vuelve a importar completo, en orden, con el motor real', function () {
    Queue::fake();
    $this->artisan('imports:export-demo')->assertSuccessful();

    $exported = collect(DEMO_TEMPLATES)->mapWithKeys(fn (string $t): array => [$t => collect(array_slice(demoCsv($t), 1))]);

    DB::statement('TRUNCATE kardex_records, student_progress, students, subject_prerequisites, study_plan_subjects, specialties, subjects, study_plans, career_campus, careers, departments CASCADE');

    $admin = User::factory()->admin()->create();
    $results = [];
    $warned = [];

    foreach (DEMO_TEMPLATES as $template) {
        $file = new UploadedFile(Storage::disk('imports')->path("demo/{$template}.csv"), "{$template}.csv", 'text/csv', null, true);
        $batch = app(StoreImportBatch::class)->handle($admin, $template, $file);
        $batch = app(ValidateImportBatch::class)->handle($batch);
        $batch = app(ApplyImportBatch::class)->handle($batch->fresh(), $admin);
        $results[$template] = [$batch->status->value, $batch->error_rows, $batch->created_rows];
        $warned[$template] = DB::table('import_rows')->where('batch_id', $batch->id)->pluck('warnings')
            ->flatMap(fn (string $w): array => array_keys(json_decode($w, true)))->unique()->sort()->values()->all();
    }

    expect($results)->toBe(collect(DEMO_TEMPLATES)->mapWithKeys(fn (string $t): array => [$t => ['applied', 0, $exported[$t]->count()]])->all())
        // Avisos esperados del demo: los planes 2004 inactivos y las materias con el mismo nombre y otra clave.
        ->and($warned)->toBe(['departments' => [], 'careers' => [], 'study_plans' => [], 'subjects' => ['name', 'plan'], 'prerequisites' => ['plan'], 'students' => [], 'kardex' => []])
        ->and(DB::table('departments')->count())->toBe($exported['departments']->count())
        ->and(DB::table('career_campus')->count())->toBe($exported['careers']->count())
        ->and(DB::table('careers')->count())->toBe($exported['careers']->pluck(0)->unique()->count())
        ->and(DB::table('study_plans')->count())->toBe($exported['study_plans']->count())
        ->and(DB::table('study_plan_subjects')->count())->toBe($exported['subjects']->count())
        ->and(DB::table('subjects')->count())->toBe($exported['subjects']->pluck(1)->unique()->count())
        ->and(DB::table('subject_prerequisites')->count())->toBe($exported['prerequisites']->count())
        ->and(DB::table('students')->count())->toBe($exported['students']->count())
        ->and(DB::table('kardex_records')->count())->toBe($exported['kardex']->count());
});
