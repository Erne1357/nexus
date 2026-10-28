<?php

use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Queries\ImportMappingQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
| ImportMappingQuery: los mapeos de columnas que el asistente ofrece para una plantilla (solo los
| activos, por nombre) y el que el admin eligió.
*/

it('lista los mapeos activos de la plantilla, por nombre', function () {
    ImportMapping::factory()->create(['template' => 'test-campuses', 'name' => 'SII 2026']);
    ImportMapping::factory()->create(['template' => 'test-campuses', 'name' => 'Escolares']);
    ImportMapping::factory()->create(['template' => 'test-campuses', 'name' => 'Viejo', 'is_active' => false]);
    ImportMapping::factory()->create(['template' => 'test-campuses', 'name' => 'Borrado'])->delete();
    ImportMapping::factory()->create(['template' => 'other-template', 'name' => 'Otra plantilla']);

    expect(app(ImportMappingQuery::class)->forTemplate('test-campuses')->pluck('name')->all())
        ->toBe(['Escolares', 'SII 2026']);
});

it('carga el mapeo elegido solo si es un mapeo activo de la plantilla', function () {
    $mapping = ImportMapping::factory()->create(['template' => 'test-campuses']);
    $inactive = ImportMapping::factory()->create(['template' => 'test-campuses', 'is_active' => false]);
    $query = app(ImportMappingQuery::class);

    expect($query->findActiveFor('test-campuses', $mapping->id)->is($mapping))->toBeTrue()
        ->and(fn () => $query->findActiveFor('other-template', $mapping->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findActiveFor('test-campuses', $inactive->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $query->findActiveFor('test-campuses', '1 OR 1=1'))->toThrow(ModelNotFoundException::class);
});
