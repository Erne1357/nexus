<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\KardexTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Jobs\RecomputeStudentProgress;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `kardex` (tarea 1.4): el historial del SII por número de control, clave de materia y periodo. El
| archivo identifica la fila por esas claves, pero la tabla guarda `student_id` y `subject_id`: la plantilla
| los busca con recordKey(). Aplicar un lote de kardex despacha el recálculo del progreso.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create();
    $this->student = Student::factory()->for($this->plan)->create(['control_number' => '19111234', 'career_id' => $this->plan->career_id]);
    $this->subject = Subject::factory()->create(['code' => 'ACF-0901', 'credits' => 5]);
    placeInPlan($this->plan, $this->subject);
    $this->admin = User::factory()->admin()->create();
    $this->context = new ImportContext($this->admin);
    $this->template = new KardexTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function kardexRow(array $changes = []): array
{
    return (new KardexTemplate)->normalize([
        'control_number' => '19111234',
        'subject_code' => 'ACF-0901',
        'term_code' => '20253',
        'grade' => 85,
        'result' => 'aprobada',
        'attempt' => 1,
        ...$changes,
    ]);
}

it('está registrada en Imports y no ofrece desactivar faltantes: el kardex no tiene is_active', function () {
    expect(app(TemplateRegistry::class)->get('kardex'))
        ->toBeInstanceOf(KardexTemplate::class)
        ->label()->toBe('Kardex')
        ->deactivatesMissing()->toBeFalse();
});

it('una fila de un alumno y una materia que existen no trae errores', function () {
    expect($this->template->validate(kardexRow(), $this->context)->errors)->toBe([]);
});

it('revisa la fila: alumno y materia que existan, periodo del SII, resultado y oportunidad', function (array $changes, string $field, string $message) {
    expect($this->template->validate(kardexRow($changes), $this->context)->errors)->toBe([$field => [$message]]);
})->with([
    'alumno que no existe' => [['control_number' => '99999999'], 'control_number', 'No hay un alumno con el número de control 99999999.'],
    'materia que no existe' => [['subject_code' => 'XXX-0000'], 'subject_code', 'No hay una materia con la clave XXX-0000.'],
    'periodo con otro formato' => [['term_code' => 'ENE-JUN 2025'], 'term_code', 'El periodo debe ser el código del SII: año y 1, 2 o 3, como 20251.'],
    'resultado desconocido' => [['result' => 'X'], 'result', 'El resultado debe ser aprobada, reprobada, cursando o baja.'],
    'oportunidad 0' => [['attempt' => 0], 'attempt', 'La oportunidad empieza en 1.'],
]);

it('escribe la fila con el alumno y la materia de la base, y al reaplicarla la actualiza sin duplicar', function () {
    $this->template->apply(kardexRow(), $this->context);
    $this->template->apply(kardexRow(['grade' => 90]), $this->context);

    expect(KardexRecord::sole())
        ->student_id->toBe($this->student->id)
        ->subject_id->toBe($this->subject->id)
        ->term_code->toBe('20253')
        ->grade->toBe('90.00')
        ->result->toBe(KardexResult::Aprobada)
        ->attempt->toBe(1);
});

it('busca al alumno y la materia una sola vez por corrida, aunque se repitan en muchas filas', function () {
    DB::enableQueryLog();

    foreach (['20251', '20252', '20253'] as $term) {
        $row = kardexRow(['term_code' => $term]);
        $this->template->validate($row, $this->context);
        $this->template->apply($row, $this->context);
    }

    $lookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/from "(students|subjects)"/', $sql) === 1);

    expect($lookups)->toHaveCount(2)
        ->and(KardexRecord::count())->toBe(3);
});

/**
 * Sube y valida un CSV de kardex con la plantilla registrada en Imports.
 */
function validatedKardexBatch(User $admin, string $csv)
{
    $batch = app(StoreImportBatch::class)->handle($admin, 'kardex', UploadedFile::fake()->createWithContent('kardex.csv', $csv));

    return app(ValidateImportBatch::class)->handle($batch);
}

it('aplicar un lote de kardex despacha el recálculo del progreso', function () {
    Storage::fake('imports');
    Queue::fake([RecomputeStudentProgress::class]);
    $batch = validatedKardexBatch($this->admin, "control_number,subject_code,term_code,grade,result,attempt\n19111234,ACF-0901,20253,85,AC,1\n");

    app(ApplyImportBatch::class)->handle($batch, $this->admin);

    expect($batch->rows()->sole()->status)->toBe(RowStatus::Applied);
    Queue::assertPushed(RecomputeStudentProgress::class);
});

it('con la cola trabajando, al aplicar el kardex el progreso del alumno queda calculado', function () {
    Storage::fake('imports');
    $batch = validatedKardexBatch($this->admin, "control_number,subject_code,term_code,grade,result,attempt\n19111234,ACF-0901,20253,85,AC,1\n");

    app(ApplyImportBatch::class)->handle($batch, $this->admin);

    expect(StudentProgress::where('student_id', $this->student->id)->sole())
        ->credits_approved->toBe(5)
        ->subjects_pending->toBe([]);
});

it('dos filas del mismo alumno, materia y periodo en un archivo: la segunda es error', function () {
    Storage::fake('imports');
    $batch = validatedKardexBatch($this->admin, "control_number,subject_code,term_code,grade,result,attempt\n19111234,ACF-0901,20253,85,AC,1\n19111234,acf-0901,2025-3,60,NA,1\n");

    expect($batch->rows()->orderBy('row_number')->pluck('status')->all())->toBe([RowStatus::Valid, RowStatus::Error]);
});
