<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\KardexTemplate;
use App\Modules\Students\Database\Seeders\ClosingGrades;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Storage;

/*
| `imports:export-demo --closing-grades`: el kárdex de cierre del ensayo del re-análisis (8.11). Fixture mínimo: 10 alumnos
| cursando dos materias en 20263 (más una fila aprobada de otro periodo que no debe salir).
*/
beforeEach(function () {
    Storage::fake('imports');
    Term::factory()->current()->create(['code' => '20271']);

    $a = Subject::factory()->create(['code' => 'CL-A']);
    $b = Subject::factory()->create(['code' => 'CL-B']);

    foreach (range(1, 10) as $i) {
        $student = Student::factory()->create(['control_number' => sprintf('2700%04d', $i)]);
        KardexRecord::factory()->for($student)->for($a)->create(['term_code' => '20263', 'result' => KardexResult::Cursando, 'grade' => null, 'attempt' => 1]);
        KardexRecord::factory()->for($student)->for($b)->create(['term_code' => '20263', 'result' => KardexResult::Cursando, 'grade' => null, 'attempt' => 1]);
        KardexRecord::factory()->for($student)->for($b)->create(['term_code' => '20261', 'result' => KardexResult::Aprobada, 'grade' => 90, 'attempt' => 1]);
    }
});

/**
 * @return list<list<string|null>>
 */
function closingCsv(string $name): array
{
    $stream = fopen(Storage::disk('imports')->path("cierre/{$name}.csv"), 'r');
    $rows = [];

    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

it('escribe el kárdex de cierre del periodo anterior con las columnas de la plantilla y la regla de la demo', function () {
    $this->artisan('imports:export-demo', ['--dir' => 'cierre', '--captured-share' => 0.5, '--failure-share' => 0.3])->assertSuccessful();

    $all = closingCsv('kardex-cierre-20263');
    $rows = array_slice($all, 1);
    $failed = collect($rows)->where(4, 'reprobada')->pluck(0)->sort()->values()->all();
    $expected = ClosingGrades::split(
        collect(range(1, 10))->map(fn (int $i): array => ['id' => sprintf('2700%04d', $i), 'key' => sprintf('2700%04d', $i).'|CL-A'])->all(),
        0.3,
        2027,
    )['failed'];

    expect($all[0])->toBe(array_values((new KardexTemplate)->fields()))
        ->and($rows)->toHaveCount(10)
        ->and(collect($rows)->pluck(1)->unique()->all())->toBe(['CL-A'])
        ->and(collect($rows)->pluck(2)->unique()->all())->toBe(['20263'])
        ->and(collect($rows)->where(4, 'reprobada')->pluck(3)->unique()->all())->toBe(['55'])
        ->and(collect($rows)->where(4, 'aprobada')->pluck(3)->unique()->all())->toBe(['82'])
        ->and($failed)->toHaveCount(3)
        ->and($failed)->toBe(collect($expected)->sort()->values()->all());
});

it('el mismo comando da el mismo archivo', function () {
    $this->artisan('imports:export-demo', ['--dir' => 'cierre'])->assertSuccessful();
    $first = file_get_contents(Storage::disk('imports')->path('cierre/kardex-cierre-20263.csv'));

    $this->artisan('imports:export-demo', ['--dir' => 'cierre'])->assertSuccessful();

    expect(file_get_contents(Storage::disk('imports')->path('cierre/kardex-cierre-20263.csv')))->toBe($first);
});

it('el kárdex de cierre pasa por la plantilla kardex sin errores', function () {
    $this->artisan('imports:export-demo', ['--dir' => 'cierre'])->assertSuccessful();

    $admin = User::factory()->admin()->create();
    $batch = ImportBatch::factory()->create(['template' => 'kardex', 'uploaded_by' => $admin->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->copy('cierre/kardex-cierre-20263.csv', $batch->file_path);
    $batch = app(ValidateImportBatch::class)->handle($batch);

    expect([$batch->status->value, $batch->error_rows, $batch->total_rows > 0])->toBe(['validated', 0, true]);
});

it('no escribe el kárdex de cierre si el periodo no tiene filas cursando', function () {
    $this->artisan('imports:export-demo', ['--dir' => 'cierre', '--closing-grades' => '20252'])->assertSuccessful();

    Storage::disk('imports')->assertMissing('cierre/kardex-cierre-20252.csv');
});
