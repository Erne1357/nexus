<?php

use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
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
