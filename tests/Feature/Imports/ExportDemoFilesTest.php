<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
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

const DEMO_TEMPLATES = ['departments', 'careers', 'study_plans', 'subjects', 'prerequisites', 'students', 'kardex', 'teachers', 'positions'];

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

it('escribe un archivo de salones por campus, que valida con 0 errores en su campus', function () {
    $c2 = Campus::where('code', 'C2')->firstOrFail();
    $c1 = Campus::where('code', 'C1')->firstOrFail();
    $owner = Department::query()->firstOrFail();
    $a = Building::factory()->for($c2)->create(['code' => 'XA']);
    Room::factory()->for($a)->ofType(RoomType::Laboratorio)->capacity(1200)->ownedBy($owner)->create(['code' => 'XA-1', 'name' => 'Lab de prueba']);
    Room::factory()->for($a)->inactive()->create(['code' => 'XA-2']);
    Room::factory()->for($a)->create(['code' => 'XA-3'])->delete();
    Room::factory()->for(Building::factory()->for($c1)->create(['code' => 'XB']))->create(['code' => 'XB-1']);

    $this->artisan('imports:export-demo')->assertSuccessful();

    $admin = User::factory()->admin()->create();
    $files = Storage::disk('imports')->allFiles('demo');
    $rows = fn (string $campus): array => array_slice(demoCsv("rooms-{$campus}"), 1);

    $exported = array_column($rows('C2'), 1);
    $expected = DB::table('rooms')->where('campus_id', $c2->id)->whereNull('deleted_at')->pluck('code')->all();
    $lab = collect($rows('C2'))->firstWhere(1, 'XA-1');

    expect($files)->toContain('demo/rooms-C1.csv', 'demo/rooms-C2.csv')
        ->and(collect($exported)->sort()->values()->all())->toBe(collect($expected)->sort()->values()->all())
        ->and(count($exported))->toBeGreaterThan(80)
        ->and($exported)->toContain('XA-1', 'XA-2')->not->toContain('XA-3')
        ->and(array_column($rows('C1'), 1))->toBe(['XB-1'])
        ->and($lab)->toBe(['XA', 'XA-1', 'Lab de prueba', 'laboratorio', '1200', $owner->code]);

    foreach ([$c1, $c2] as $campus) {
        $batch = app(StoreImportBatch::class)->handle($admin, 'rooms', new UploadedFile(Storage::disk('imports')->path("demo/rooms-{$campus->code}.csv"), "rooms-{$campus->code}.csv", 'text/csv', null, true), $campus);
        $batch = app(ValidateImportBatch::class)->handle($batch);

        expect([$campus->code, $batch->status->value, $batch->error_rows, $batch->total_rows > 0])->toBe([$campus->code, 'validated', 0, true]);
    }
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
    // Un directorio donde va el archivo: fopen falla también como root.
    Storage::disk('imports')->makeDirectory('demo/departments.csv');

    $this->artisan('imports:export-demo')->expectsOutputToContain('No se pudo escribir demo/departments.csv')->assertFailed();

    expect(Storage::disk('imports')->allFiles())->toBe([]);
});

it('escribe la calificación sin ceros de más y sin tocar los enteros', function () {
    $ids = DB::table('kardex_records')->whereNull('deleted_at')->orderBy('id')->limit(3)->pluck('id');
    DB::table('kardex_records')->where('id', $ids[0])->update(['grade' => 100]);
    DB::table('kardex_records')->where('id', $ids[1])->update(['grade' => 70]);
    DB::table('kardex_records')->where('id', $ids[2])->update(['grade' => 85.5]);

    $this->artisan('imports:export-demo')->assertSuccessful();

    $grades = collect(array_slice(demoCsv('kardex'), 1))->pluck(3)->all();

    expect($grades)->toContain('100', '70', '85.5')->not->toContain('1', '7', '85.50');
});

it('lo exportado se vuelve a importar completo, en orden, con el motor real', function () {
    Queue::fake();
    $this->artisan('imports:export-demo')->assertSuccessful();

    $exported = collect(DEMO_TEMPLATES)->mapWithKeys(fn (string $t): array => [$t => collect(array_slice(demoCsv($t), 1))]);

    DB::statement('TRUNCATE teacher_positions, teacher_departments, teachers, kardex_records, student_progress, students, subject_prerequisites, study_plan_subjects, specialties, subjects, study_plans, career_campus, careers, departments CASCADE');

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

    expect($results)->toBe(collect(DEMO_TEMPLATES)->mapWithKeys(fn (string $t): array => [$t => ['applied', 0, $t === 'teachers' ? $exported[$t]->pluck(0)->unique()->count() : $exported[$t]->count()]])->all())
        // Avisos esperados del demo: los planes 2004 inactivos y las materias con el mismo nombre y otra clave.
        ->and($warned)->toBe(['departments' => [], 'careers' => [], 'study_plans' => [], 'subjects' => ['name', 'plan'], 'prerequisites' => ['plan'], 'students' => [], 'kardex' => [], 'teachers' => [], 'positions' => []])
        ->and(DB::table('departments')->count())->toBe($exported['departments']->count())
        ->and(DB::table('career_campus')->count())->toBe($exported['careers']->count())
        ->and(DB::table('careers')->count())->toBe($exported['careers']->pluck(0)->unique()->count())
        ->and(DB::table('study_plans')->count())->toBe($exported['study_plans']->count())
        ->and(DB::table('study_plan_subjects')->count())->toBe($exported['subjects']->count())
        ->and(DB::table('subjects')->count())->toBe($exported['subjects']->pluck(1)->unique()->count())
        ->and(DB::table('subject_prerequisites')->count())->toBe($exported['prerequisites']->count())
        ->and(DB::table('students')->count())->toBe($exported['students']->count())
        ->and(DB::table('kardex_records')->count())->toBe($exported['kardex']->count())
        ->and(DB::table('teachers')->count())->toBe($exported['teachers']->pluck(0)->unique()->count())
        ->and(DB::table('teacher_departments')->count())->toBe($exported['teachers']->count())
        ->and(DB::table('teacher_departments')->where('is_primary', true)->count())->toBe($exported['teachers']->pluck(0)->unique()->count())
        ->and(DB::table('teacher_positions')->count())->toBe($exported['positions']->count());
});

it('exporta un renglón por adscripción con la principal primero y una plaza por renglón, sin borrados', function () {
    $this->artisan('imports:export-demo')->assertSuccessful();

    $adscriptions = DB::table('teacher_departments as td')->join('teachers as t', 't.id', '=', 'td.teacher_id')
        ->whereNull('td.deleted_at')->whereNull('t.deleted_at')->count();
    $positions = DB::table('teacher_positions as p')->join('teachers as t', 't.id', '=', 'p.teacher_id')
        ->whereNull('p.deleted_at')->whereNull('t.deleted_at')->count();
    $teachers = collect(array_slice(demoCsv('teachers'), 1));
    $firstOfEach = $teachers->unique(0)->pluck(2, 0);
    $primary = DB::table('teacher_departments as td')->join('teachers as t', 't.id', '=', 'td.teacher_id')
        ->join('departments as d', 'd.id', '=', 'td.department_id')
        ->where('td.is_primary', true)->whereNull('td.deleted_at')->whereNull('t.deleted_at')->pluck('d.code', 't.employee_number');

    expect($teachers)->toHaveCount($adscriptions)
        ->and(demoCsv('teachers')[0])->toBe(['número de empleado', 'nombre', 'departamento', 'correo', 'grado', 'activo', 'fecha de ingreso'])
        ->and($teachers->map(fn (array $r): string => "{$r[0]}/{$r[2]}")->unique())->toHaveCount($adscriptions)
        ->and($firstOfEach->all())->toEqual($primary->all())
        ->and(demoCsv('positions'))->toHaveCount($positions + 1);
});
