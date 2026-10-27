<?php

use App\Modules\Imports\Templates\ColumnMatcher;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| ColumnMatcher decide qué columna del archivo va a qué campo de la plantilla: con un mapeo guardado,
| o sin él por nombre de campo o etiqueta. Lo usan la validación del lote y el asistente, que muestra
| la propuesta antes de validar.
*/

it('reconoce las columnas por campo o por etiqueta sin importar mayúsculas, acentos ni espacios', function () {
    $columns = (new ColumnMatcher)->match(new CampusesTemplate, ['CLAVE ', 'Nómbre', 'Modality', 'Otra']);

    expect($columns)->toBe(['CLAVE ' => 'code', 'Nómbre' => 'name', 'Modality' => 'modality']);
});

it('una columna con el nombre de un campo gana sobre la etiqueta de otro campo', function () {
    $template = new class extends CampusesTemplate
    {
        public function fields(): array
        {
            return ['code' => 'clave', 'name' => 'code', 'modality' => 'modalidad'];
        }
    };

    expect((new ColumnMatcher)->match($template, ['code', 'clave']))->toBe(['code' => 'code']);
});

it('si dos columnas coinciden con el mismo campo, se queda con la primera', function () {
    expect((new ColumnMatcher)->match(new CampusesTemplate, ['Clave', 'CLAVE', 'nombre']))
        ->toBe(['Clave' => 'code', 'nombre' => 'name']);
});

it('con un mapeo guardado usa solo el mapeo: sin columnas que no vienen ni campos que la plantilla no tiene', function () {
    $columns = (new ColumnMatcher)->match(new CampusesTemplate, ['CVE', 'DESC', 'nombre'], [
        'CVE' => 'code',
        'DESC' => 'retired',
        'MOD' => 'modality',
    ]);

    expect($columns)->toBe(['CVE' => 'code']);
});

it('con un mapeo guardado reconoce una columna de nombre numérico, que el json devuelve como número', function () {
    $mapping = json_decode(json_encode(['2026' => 'code']), true);

    expect((new ColumnMatcher)->match(new CampusesTemplate, ['2026', 'nombre'], $mapping))->toBe(['2026' => 'code']);
});

it('dice qué columnas de un mapeo guardado no vienen en el archivo', function () {
    expect((new ColumnMatcher)->absentColumns(['CVE', '2026'], ['CVE' => 'code', 'MOD' => 'modality', 2026 => 'name', 'DESC' => 'name']))
        ->toBe(['MOD', 'DESC']);
});

it('dice a qué campos que la plantilla no tiene apuntan unas columnas', function () {
    expect((new ColumnMatcher)->unknownFields(new CampusesTemplate, ['CVE' => 'code', 'X' => 'retired', 'Y' => 'id', 'Z' => 'retired']))
        ->toBe(['retired', 'id']);
});

it('dice qué campos de la clave natural se quedaron sin columna, y lo explica con su etiqueta', function () {
    $matcher = new ColumnMatcher;

    expect($matcher->missingKey(new CampusesTemplate, ['nombre' => 'name']))->toBe(['code'])
        ->and($matcher->missingKey(new CampusesTemplate, ['clave' => 'code']))->toBe([])
        ->and($matcher->describeMissingKey(new CampusesTemplate, ['nombre' => 'name']))->toBe('Falta la columna de clave, que identifica cada registro.')
        ->and($matcher->describeMissingKey(new CampusesTemplate, ['clave' => 'code']))->toBeNull();
});
