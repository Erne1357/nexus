<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Imports\Enums\RowIssue;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Queries\ImportBatchQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\LazyCollection;

/*
| ImportBatchQuery: lo que el asistente de importación lee de un lote. Los lotes recientes para
| retomarlos, el lote abierto, sus filas con error o advertencia por número de fila, cuántas traen
| advertencia, lo que lleva escrito la aplicación y las filas con error para descargarlas.
*/

beforeEach(function () {
    $this->query = app(ImportBatchQuery::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function importRowOf(ImportBatch $batch, int $number, RowStatus $status, array $attributes = []): ImportRow
{
    return ImportRow::factory()->for($batch, 'batch')->create(['row_number' => $number, 'status' => $status, ...$attributes]);
}

it('lista los lotes más recientes primero, hasta el límite, con su campus y quién los subió', function () {
    $old = ImportBatch::factory()->create(['created_at' => now()->subDays(2)]);
    $newest = ImportBatch::factory()->create(['created_at' => now()]);
    $middle = ImportBatch::factory()->create(['created_at' => now()->subDay()]);

    $recent = $this->query->recent(2);

    expect($recent->modelKeys())->toBe([$newest->id, $middle->id])
        ->and($recent->first()->relationLoaded('uploader'))->toBeTrue()
        ->and($recent->first()->relationLoaded('campus'))->toBeTrue()
        ->and($this->query->recent()->modelKeys())->toContain($old->id);
});

it('los lotes recientes conservan su campus aunque se haya retirado', function () {
    $campus = Campus::factory()->create(['code' => 'C9']);
    ImportBatch::factory()->create(['campus_id' => $campus->id]);
    $campus->delete();

    expect($this->query->recent()->sole()->campus?->code)->toBe('C9');
});

it('carga el lote abierto por su id y trata un id que no es uuid como uno que no existe', function () {
    $batch = ImportBatch::factory()->create();

    expect($this->query->findOrFail($batch->id)->is($batch))->toBeTrue()
        ->and(fn () => $this->query->findOrFail('1 OR 1=1'))->toThrow(ModelNotFoundException::class);
});

it('lista las filas con error del lote por número de fila, paginadas', function () {
    $batch = ImportBatch::factory()->create();
    importRowOf($batch, 9, RowStatus::Error);
    importRowOf($batch, 3, RowStatus::Error);
    importRowOf($batch, 4, RowStatus::Valid);
    importRowOf($batch, 5, RowStatus::Warning, ['warnings' => ['code' => ['Inactivo.']]]);
    importRowOf(ImportBatch::factory()->create(), 2, RowStatus::Error);

    $page = $this->query->issues($batch, RowIssue::Errors, perPage: 1);

    expect($page->total())->toBe(2)
        ->and($page->items()[0]->row_number)->toBe(3);
});

it('lista las filas que se aplican con advertencia, también las ya aplicadas, y no las de error', function () {
    $batch = ImportBatch::factory()->create();
    importRowOf($batch, 2, RowStatus::Applied, ['warnings' => ['code' => ['Inactivo.']]]);
    importRowOf($batch, 3, RowStatus::Warning, ['warnings' => ['code' => ['Inactivo.']]]);
    importRowOf($batch, 4, RowStatus::Applied);
    importRowOf($batch, 5, RowStatus::Error, ['warnings' => ['code' => ['Inactivo.']], 'errors' => ['name' => ['Falta.']]]);
    importRowOf($batch, 6, RowStatus::Valid, ['warnings' => []]);

    expect($this->query->issues($batch, RowIssue::Warnings)->pluck('row_number')->all())->toBe([2, 3])
        ->and($this->query->warningRows($batch))->toBe(2);
});

it('da lo que la aplicación lleva escrito y las filas con error que se saltan', function () {
    $batch = ImportBatch::factory()->create(['created_rows' => 5, 'updated_rows' => 2, 'unchanged_rows' => 1]);
    importRowOf($batch, 2, RowStatus::Error);
    importRowOf($batch, 3, RowStatus::Applied);

    expect($this->query->appliedCounts($batch))->toBe(['created' => 5, 'updated' => 2, 'unchanged' => 1, 'skipped' => 1]);
});

it('recorre las filas con error en el orden del archivo, de a poco, con la fila como vino', function () {
    $batch = ImportBatch::factory()->create();
    importRowOf($batch, 7, RowStatus::Error, ['raw' => ['CVE' => 'X7'], 'errors' => ['code' => ['Mal.']]]);
    importRowOf($batch, 2, RowStatus::Error, ['raw' => ['CVE' => 'X2'], 'errors' => ['code' => ['Mal.']]]);
    importRowOf($batch, 3, RowStatus::Valid);

    $rows = $this->query->errorRows($batch);

    expect($rows)->toBeInstanceOf(LazyCollection::class)
        ->and($rows->map(fn (ImportRow $row): array => [$row->row_number, $row->raw])->all())
        ->toBe([[2, ['CVE' => 'X2']], [7, ['CVE' => 'X7']]]);
});
