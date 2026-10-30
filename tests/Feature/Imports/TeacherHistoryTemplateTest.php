<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\HistorySource;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\TeacherHistoryTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `teacher_history` (tarea 1.13): una fila por grupo impartido (confirmado el 30 de octubre de
| 2026), con número de empleado, clave de materia, periodo del SII, campus y grupo (S13). La tabla guarda
| `teacher_id`, `subject_id` y `campus_id`: recordKey() los busca. No ofrece "desactivar faltantes".
*/

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['employee_number' => '1234567']);
    $this->subject = Subject::factory()->create(['code' => 'ACF-0901']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->admin = User::factory()->admin()->create();
    $this->context = new ImportContext($this->admin);
    $this->template = new TeacherHistoryTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function historyRow(array $changes = []): array
{
    return (new TeacherHistoryTemplate)->normalize([
        'employee_number' => '1234567',
        'subject_code' => 'ACF-0901',
        'term_code' => '20241',
        'campus_code' => 'C2',
        'group_code' => '5A',
        ...$changes,
    ]);
}

it('está registrada en Imports y no ofrece desactivar faltantes', function () {
    expect(app(TemplateRegistry::class)->get('teacher_history'))
        ->toBeInstanceOf(TeacherHistoryTemplate::class)
        ->label()->toBe('Historial docente')
        ->deactivatesMissing()->toBeFalse();
});

it('una fila de un docente, materia y campus que existen no trae errores', function () {
    expect($this->template->validate(historyRow(), $this->context)->errors)->toBe([]);
});

it('revisa la fila: docente, materia, campus, periodo del SII y grupo (S13)', function (array $changes, string $field, string $message) {
    expect($this->template->validate(historyRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'docente que no existe' => [['employee_number' => '9999999'], 'employee_number', 'No hay un docente con el número de empleado 9999999.'],
    'materia que no existe' => [['subject_code' => 'XXX-0000'], 'subject_code', 'No hay una materia con la clave XXX-0000.'],
    'campus que no existe' => [['campus_code' => 'ZZ'], 'campus_code', 'No hay un campus con la clave ZZ.'],
    'periodo con otro formato' => [['term_code' => 'ENE-JUN 2024'], 'term_code', 'El periodo debe ser el código del SII: año y 1, 2 o 3, como 20251.'],
    'sin grupo (S13)' => [['group_code' => null], 'group_code', 'El campo grupo es obligatorio.'],
]);

it('escribe la fila con los ids de la base y source import; al reaplicarla no duplica', function () {
    $this->template->apply(historyRow(), $this->context);
    $this->template->apply(historyRow(), $this->context);

    expect(TeacherSubjectHistory::sole())
        ->teacher_id->toBe($this->teacher->id)
        ->subject_id->toBe($this->subject->id)
        ->campus_id->toBe($this->campus->id)
        ->term_code->toBe('20241')
        ->group_code->toBe('5A')
        ->source->toBe(HistorySource::Import);
});

it('dos grupos del mismo periodo son dos filas', function () {
    $this->template->apply(historyRow(['group_code' => '5A']), $this->context);
    $this->template->apply(historyRow(['group_code' => '5B']), $this->context);

    expect(TeacherSubjectHistory::count())->toBe(2);
});

it('sin campus escribe la fila con campus nulo, y reaplicarla no duplica', function () {
    $this->template->apply(historyRow(['campus_code' => null]), $this->context);
    $this->template->apply(historyRow(['campus_code' => null]), $this->context);

    expect(TeacherSubjectHistory::sole()->campus_id)->toBeNull();
});

it('reaplicar una fila que ya registró Nexus no cambia su origen', function () {
    TeacherSubjectHistory::factory()->fromNexus()->create([
        'teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'campus_id' => $this->campus->id,
        'term_code' => '20241', 'group_code' => '5A',
    ]);

    $this->template->apply(historyRow(), $this->context);

    expect(TeacherSubjectHistory::sole()->source)->toBe(HistorySource::Nexus);
});

it('un registro borrado con la misma clave es error', function () {
    TeacherSubjectHistory::factory()->create([
        'teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'campus_id' => $this->campus->id,
        'term_code' => '20241', 'group_code' => '5A',
    ])->delete();

    expect($this->template->validate(historyRow(), $this->context)->errors)->toHaveKey('employee_number');
});

it('acepta el número de empleado como número de Excel, la clave en minúsculas y el periodo 2024-1', function () {
    $row = (new TeacherHistoryTemplate)->normalize([
        'employee_number' => 1234567.0, 'subject_code' => 'acf-0901', 'term_code' => '2024-1', 'campus_code' => 'c2', 'group_code' => '5a',
    ]);

    expect($this->template->validate($row, $this->context)->errors)->toBe([])
        ->and($row)->toMatchArray(['employee_number' => '1234567', 'subject_code' => 'ACF-0901', 'term_code' => '20241', 'campus_code' => 'C2']);
});

it('un lote con dos grupos y una fila repetida: dos válidas y la repetida es error', function () {
    Storage::fake('imports');
    $csv = "employee_number,subject_code,term_code,campus_code,group_code\n1234567,ACF-0901,20241,C2,5A\n1234567,ACF-0901,20241,C2,5B\n1234567,ACF-0901,20241,C2,5A\n";
    $batch = app(StoreImportBatch::class)->handle($this->admin, 'teacher_history', UploadedFile::fake()->createWithContent('h.csv', $csv));
    $batch = app(ValidateImportBatch::class)->handle($batch);
    app(ApplyImportBatch::class)->handle($batch->fresh(), $this->admin);

    expect($batch->rows()->orderBy('row_number')->pluck('status')->all())->toBe([RowStatus::Applied, RowStatus::Applied, RowStatus::Error])
        ->and(DB::table('teacher_subject_history')->count())->toBe(2);
});

it('una fila sin campus repetida en el archivo es error: ya viene en la fila 2', function () {
    Storage::fake('imports');
    $csv = "employee_number,subject_code,term_code,campus_code,group_code\n1234567,ACF-0901,20241,,5A\n1234567,ACF-0901,20241,,5A\n";
    $batch = app(StoreImportBatch::class)->handle($this->admin, 'teacher_history', UploadedFile::fake()->createWithContent('h.csv', $csv));
    $batch = app(ValidateImportBatch::class)->handle($batch);

    $rows = $batch->rows()->orderBy('row_number')->get();

    expect($rows->pluck('status')->all())->toBe([RowStatus::Valid, RowStatus::Error])
        ->and(json_encode($rows[1]->errors))->toContain('ya viene en la fila 2');
});

it('el fixture de historial se lee con sus encabezados y valida con 0 errores', function () {
    foreach (['9000001', '9000002'] as $number) {
        Teacher::factory()->create(['employee_number' => $number]);
    }
    Subject::factory()->create(['code' => 'SCC-1010']);
    $lines = array_map('str_getcsv', file(base_path('tests/Fixtures/imports/teacher_history.csv'), FILE_IGNORE_NEW_LINES), array_fill(0, 4, ','), array_fill(0, 4, '"'), array_fill(0, 4, ''));
    $header = array_shift($lines);

    expect($header)->toBe(array_values($this->template->fields()));

    foreach ($lines as $line) {
        $row = $this->template->normalize(array_combine(array_keys($this->template->fields()), $line));
        expect($this->template->validate($row, $this->context)->errors)->toBe([]);
    }
});
