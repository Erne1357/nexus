<?php

use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\CreateImportMapping;
use App\Modules\Imports\Events\ImportMappingCreated;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| CreateImportMapping guarda un mapeo de columnas para una plantilla (ADR-0005): cada layout de
| exportación del SII se mapea una vez y se reutiliza. `columns` es columna del archivo → campo de la
| plantilla. El nombre no se repite dentro de la plantilla, ni con mapeos desactivados o borrados.
*/

beforeEach(function () {
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  array<string, string>  $columns
 */
function newImportMapping(User $user, array $columns = ['CVE' => 'code', 'DESC' => 'name'], string $name = 'SII 2026', string $template = 'test-campuses'): ImportMapping
{
    return app(CreateImportMapping::class)->handle($user, $template, $name, $columns);
}

/**
 * @return array<string, list<string>>
 */
function importMappingErrors(Closure $create): array
{
    try {
        $create();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('Debió rechazar el mapeo.');
}

it('guarda el mapeo activo de la plantilla con sus columnas y quién lo creó', function () {
    $mapping = newImportMapping($this->admin, name: '  SII   2026 ')->fresh();

    expect($mapping)
        ->template->toBe('test-campuses')
        ->name->toBe('SII 2026')
        ->columns->toBe(['CVE' => 'code', 'DESC' => 'name'])
        ->is_active->toBeTrue()
        ->and($mapping->creator->is($this->admin))->toBeTrue();
});

it('rechaza un nombre vacío o que ya usa otro mapeo de la plantilla, aunque esté borrado', function () {
    ImportMapping::factory()->create(['template' => 'test-campuses', 'name' => 'Borrado'])->delete();

    expect(importMappingErrors(fn () => newImportMapping($this->admin, name: '   ')))
        ->toBe(['name' => ['Escribe un nombre para el mapeo.']])
        ->and(importMappingErrors(fn () => newImportMapping($this->admin, name: 'Borrado')))
        ->toBe(['name' => ['Ya hay un mapeo con ese nombre para esta plantilla.']])
        ->and(ImportMapping::withTrashed()->count())->toBe(1);
});

it('deja usar el mismo nombre en otra plantilla', function () {
    ImportMapping::factory()->create(['template' => 'other-template', 'name' => 'SII 2026']);

    expect(newImportMapping($this->admin)->exists)->toBeTrue();
});

it('rechaza una plantilla que no existe', function () {
    expect(importMappingErrors(fn () => newImportMapping($this->admin, template: 'nope')))
        ->toBe(['template' => ['Elige una plantilla de importación.']]);
});

it('rechaza columnas hacia campos que la plantilla no tiene', function () {
    expect(importMappingErrors(fn () => newImportMapping($this->admin, ['CVE' => 'code', 'X' => 'retired', 'Y' => 'id'])))
        ->toBe(['columns' => ['El mapeo apunta a campos que la plantilla no tiene: retired, id.']]);
});

it('rechaza dos columnas hacia el mismo campo', function () {
    expect(importMappingErrors(fn () => newImportMapping($this->admin, ['CVE' => 'code', 'DESC' => 'name', 'NOMBRE' => 'name'])))
        ->toBe(['columns' => ['El campo nombre tiene dos columnas: DESC y NOMBRE.']]);
});

it('rechaza un mapeo sin la columna de la clave natural', function () {
    expect(importMappingErrors(fn () => newImportMapping($this->admin, ['DESC' => 'name'])))
        ->toBe(['columns' => ['Falta la columna de clave, que identifica cada registro.']])
        ->and(ImportMapping::count())->toBe(0);
});

it('si otro guarda el mismo nombre entre la revisión y la escritura, responde con el error de nombre', function () {
    ImportMapping::creating(fn () => DB::table('import_mappings')->insert([
        'template' => 'test-campuses',
        'name' => 'SII 2026',
        'created_by' => $this->admin->id,
    ]));

    expect(importMappingErrors(fn () => newImportMapping($this->admin)))
        ->toBe(['name' => ['Ya hay un mapeo con ese nombre para esta plantilla.']])
        ->and(Activity::count())->toBe(0);
});

it('registra en la bitácora quién guardó el mapeo y con qué columnas', function () {
    $mapping = newImportMapping($this->admin);

    $entry = Activity::sole();

    expect($entry)
        ->event->toBe('created')
        ->description->toBe('Guardó el mapeo de columnas «SII 2026» para importar Campus (prueba)')
        ->and($entry->subject->is($mapping))->toBeTrue()
        ->and($entry->causer->is($this->admin))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['template' => 'test-campuses', 'name' => 'SII 2026', 'columns' => ['CVE' => 'code', 'DESC' => 'name'], 'is_active' => true],
        ]);
});

it('avisa con ImportMappingCreated solo después del commit', function () {
    $heard = [];
    Event::listen(ImportMappingCreated::class, function (ImportMappingCreated $event) use (&$heard): void {
        $heard[] = $event->mapping->name;
    });

    try {
        DB::transaction(function (): void {
            newImportMapping($this->admin);

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    newImportMapping($this->admin, name: 'Otro');

    expect($heard)->toBe(['Otro']);
});
