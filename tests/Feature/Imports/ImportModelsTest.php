<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Models\ImportRow;
use Illuminate\Database\UniqueConstraintViolationException;

it('un lote tiene sus filas en orden y cada fila apunta al registro que escribió', function () {
    $batch = ImportBatch::factory()->create();
    $campus = Campus::factory()->create();
    ImportRow::factory()->for($batch, 'batch')->create(['row_number' => 3]);
    ImportRow::factory()->for($batch, 'batch')->create([
        'row_number' => 2,
        'status' => RowStatus::Applied,
        'target_type' => $campus->getMorphClass(),
        'target_id' => $campus->id,
        'errors' => [],
        'warnings' => ['code' => ['Ojo.']],
    ]);

    $rows = $batch->rows;

    expect($rows->pluck('row_number')->all())->toBe([2, 3])
        ->and($rows->first()->status)->toBe(RowStatus::Applied)
        ->and($rows->first()->warnings)->toBe(['code' => ['Ojo.']])
        ->and($rows->first()->target->is($campus))->toBeTrue()
        ->and($rows->first()->batch->is($batch))->toBeTrue();
});

it('un número de fila no se repite dentro del mismo lote', function () {
    $batch = ImportBatch::factory()->create();
    ImportRow::factory()->for($batch, 'batch')->create(['row_number' => 2]);

    ImportRow::factory()->for($batch, 'batch')->create(['row_number' => 2]);
})->throws(UniqueConstraintViolationException::class);

it('un mapeo guarda columna de origen → campo, y su nombre no se repite en la plantilla', function () {
    $mapping = ImportMapping::factory()->create(['template' => 'careers', 'name' => 'SII 2026', 'columns' => ['CVE_CARRERA' => 'code']]);

    expect($mapping->fresh()->columns)->toBe(['CVE_CARRERA' => 'code']);

    ImportMapping::factory()->create(['template' => 'careers', 'name' => 'SII 2026']);
})->throws(UniqueConstraintViolationException::class);
