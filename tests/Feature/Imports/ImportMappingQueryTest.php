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

it('carga el mapeo elegido por su id y trata un id que no es uuid como uno que no existe', function () {
    $mapping = ImportMapping::factory()->create();
    $query = app(ImportMappingQuery::class);

    expect($query->findOrFail($mapping->id)->is($mapping))->toBeTrue()
        ->and(fn () => $query->findOrFail('1 OR 1=1'))->toThrow(ModelNotFoundException::class);
});
