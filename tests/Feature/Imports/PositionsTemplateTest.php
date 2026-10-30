<?php

use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\PositionsTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Storage;

/*
| Plantilla `positions` (tarea 10): las plazas de los docentes del SII. La clave es docente, tipo y «desde»;
| las horas deben cuadrar con el tipo (S3); dos plazas vigentes del mismo docente se aceptan.
*/

beforeEach(function () {
    $this->teacher = Teacher::factory()->create(['employee_number' => '1001']);
    $this->context = new ImportContext(User::factory()->admin()->create());
    $this->template = new PositionsTemplate;
});

/**
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function positionRow(array $changes = []): array
{
    return (new PositionsTemplate)->normalize([
        'employee_number' => '1001', 'kind' => 'TIEMPO COMPLETO', 'base_hours' => '40',
        'valid_from' => '01/08/2026', 'valid_to' => '31/12/2026', ...$changes,
    ]);
}

it('está registrada en Imports con su clave y su nombre', function () {
    expect(app(TemplateRegistry::class)->get('positions'))
        ->toBeInstanceOf(PositionsTemplate::class)
        ->label()->toBe('Plazas');
});

it('una fila completa no trae errores ni advertencias', function () {
    $issues = $this->template->validate(positionRow(), $this->context);

    expect($issues->errors)->toBe([])->and($issues->warnings)->toBe([]);
});

it('da de alta la plaza con el tipo del SII y las fechas en ISO', function (string $name, int $hours, PositionKind $kind) {
    $position = $this->template->apply(positionRow(['kind' => $name, 'base_hours' => $hours]), $this->context)->fresh();

    expect($position)->teacher_id->toBe($this->teacher->id)->kind->toBe($kind)->base_hours->toBe($hours)
        ->and($position->valid_from->toDateString())->toBe('2026-08-01')
        ->and($position->valid_to->toDateString())->toBe('2026-12-31');
})->with([
    'tiempo completo' => ['TIEMPO COMPLETO', 40, PositionKind::TiempoCompleto],
    'tres cuartos' => ['3/4', 30, PositionKind::TresCuartos],
    'medio tiempo' => ['MEDIO TIEMPO', 20, PositionKind::MedioTiempo],
    'asignatura' => ['ASIGNATURA', 12, PositionKind::Asignatura],
    'honorarios' => ['HONORARIOS', 0, PositionKind::Honorarios],
]);

it('S3: unas horas que no cuadran con el tipo son error', function (string $kind, int $hours) {
    expect($this->template->validate(positionRow(['kind' => $kind, 'base_hours' => $hours]), $this->context)->errors)
        ->toHaveKey('base_hours');
})->with([
    'tiempo completo con 30' => ['TIEMPO COMPLETO', 30],
    'tres cuartos con 40' => ['3/4', 40],
    'medio tiempo con 10' => ['MEDIO TIEMPO', 10],
    'honorarios con 5' => ['HONORARIOS', 5],
    'asignatura con 0' => ['ASIGNATURA', 0],
    'asignatura con 41' => ['ASIGNATURA', 41],
]);

it('un tipo desconocido es error', function () {
    expect($this->template->validate(positionRow(['kind' => 'Interino']), $this->context)->errors)->toHaveKey('kind');
});

it('un docente que no existe, o que está borrado, es error', function () {
    Teacher::factory()->create(['employee_number' => '1002'])->delete();

    expect($this->template->validate(positionRow(['employee_number' => '9999']), $this->context)->errors)
        ->toBe(['employee_number' => ['No hay un docente con el número de empleado 9999.']])
        ->and($this->template->validate(positionRow(['employee_number' => '1002']), $this->context)->errors)
        ->toHaveKey('employee_number');
});

it('«hasta» antes de «desde» es error; el mismo día se acepta', function () {
    expect($this->template->validate(positionRow(['valid_to' => '31/07/2026']), $this->context)->errors)->toHaveKey('valid_to')
        ->and($this->template->validate(positionRow(['valid_to' => '01/08/2026']), $this->context)->errors)->toBe([]);
});

it('rechaza una fecha imposible', function () {
    expect($this->template->validate(positionRow(['valid_from' => '31/02/2026']), $this->context)->errors)->toHaveKey('valid_from');
});

it('dos plazas vigentes del mismo docente se aceptan', function () {
    $this->template->apply(positionRow(), $this->context);
    $this->template->apply(positionRow(['kind' => 'ASIGNATURA', 'base_hours' => 8]), $this->context);

    expect($this->template->validate(positionRow(['kind' => 'HONORARIOS', 'base_hours' => 0]), $this->context)->errors)->toBe([])
        ->and($this->teacher->positions()->count())->toBe(2);
});

it('la clave es docente, tipo y desde: otro desde es otra plaza; aplicar dos veces no cambia nada', function () {
    $this->template->apply(positionRow(), $this->context);
    $same = $this->template->apply(positionRow(), $this->context);
    $this->template->apply(positionRow(['valid_from' => '01/01/2027', 'valid_to' => null]), $this->context);

    expect($same->wasRecentlyCreated)->toBeFalse()->and($same->wasChanged())->toBeFalse()
        ->and(TeacherPosition::count())->toBe(2)
        ->and($this->template->keyFor(positionRow(), $this->context))
        ->not->toBe($this->template->keyFor(positionRow(['valid_from' => '01/01/2027']), $this->context));
});

it('actualiza el fin de vigencia de la plaza con la misma clave, sin pisarlo con vacío', function () {
    $this->template->apply(positionRow(), $this->context);
    $this->template->apply(positionRow(['valid_to' => '30/06/2027']), $this->context);
    $this->template->apply(positionRow(['valid_to' => null]), $this->context);

    expect(TeacherPosition::sole()->valid_to->toDateString())->toBe('2027-06-30');
});

it('una plaza sin «desde» es una plaza sin límite y se puede repetir sin duplicarse', function () {
    $this->template->apply(positionRow(['valid_from' => null, 'valid_to' => null]), $this->context);
    $this->template->apply(positionRow(['valid_from' => null, 'valid_to' => null]), $this->context);

    expect(TeacherPosition::sole()->valid_from)->toBeNull();
});

it('no ofrece desactivar faltantes', function () {
    expect($this->template->deactivatesMissing())->toBeFalse();
});

it('busca cada docente una sola vez por corrida', function () {
    DB::enableQueryLog();

    foreach (['TIEMPO COMPLETO', 'ASIGNATURA'] as $kind) {
        $row = positionRow(['kind' => $kind, 'base_hours' => $kind === 'ASIGNATURA' ? 5 : 40]);
        $this->template->validate($row, $this->context);
        $this->template->apply($row, $this->context);
    }

    $lookups = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/from "teachers"/', $sql) === 1);

    expect($lookups)->toHaveCount(1);
});

it('el archivo de ejemplo anonimizado se valida y se aplica sin errores', function () {
    Storage::fake('imports');
    foreach (['90001', '90002', '90003'] as $number) {
        Teacher::factory()->create(['employee_number' => $number]);
    }
    $batch = ImportBatch::factory()->create(['template' => 'positions', 'uploaded_by' => $this->context->user->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, file_get_contents(base_path('tests/Fixtures/imports/positions.csv')));

    $batch = app(ValidateImportBatch::class)->handle($batch);
    $batch = app(ApplyImportBatch::class)->handle($batch->fresh(), $this->context->user);

    expect([$batch->error_rows, $batch->created_rows])->toBe([0, 4])
        ->and(TeacherPosition::count())->toBe(4);
});
