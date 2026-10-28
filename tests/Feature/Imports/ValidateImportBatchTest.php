<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Events\BatchValidated;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| ValidateImportBatch lee el archivo de un lote recién subido, mapea sus columnas a los campos de la
| plantilla, normaliza y valida cada fila y la guarda en `import_rows` con sus errores y advertencias.
| No escribe en el catálogo: eso es ApplyImportBatch. Un archivo que no se puede leer, o sin la columna
| de la clave natural, deja el lote `failed` con el motivo que verá el admin.
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
});

function batchWithFile(string $contents, string $extension = 'csv', array $attributes = []): ImportBatch
{
    $batch = ImportBatch::factory()->create(['uploaded_by' => test()->admin->id, ...$attributes]);
    $batch->update(['file_path' => "{$batch->id}.{$extension}"]);
    Storage::disk('imports')->put($batch->file_path, $contents);

    return $batch;
}

/**
 * El contenido de un XLSX con las filas dadas.
 *
 * @param  list<list<mixed>>  $rows
 */
function campusXlsx(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'nexus-xlsx');
    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();
    $contents = (string) file_get_contents($path);
    unlink($path);

    return $contents;
}

function validateBatch(ImportBatch $batch): ImportBatch
{
    return app(ValidateImportBatch::class)->handle($batch);
}

/**
 * @return array<int, array{status: RowStatus, errors: array<string, list<string>>, warnings: array<string, list<string>>}>
 */
function validatedRows(ImportBatch $batch): array
{
    return $batch->rows()->orderBy('row_number')->get()->mapWithKeys(fn (ImportRow $row): array => [
        $row->row_number => ['status' => $row->status, 'errors' => $row->errors, 'warnings' => $row->warnings],
    ])->all();
}

it('valida cada fila y deja el lote validado con sus cuentas', function () {
    $batch = validateBatch(batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\nC8,,presencial\n"));

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Validated)
        ->total_rows->toBe(2)
        ->valid_rows->toBe(1)
        ->error_rows->toBe(1)
        ->and(validatedRows($batch))->toBe([
            2 => ['status' => RowStatus::Valid, 'errors' => [], 'warnings' => []],
            3 => ['status' => RowStatus::Error, 'errors' => ['name' => ['El campo nombre es obligatorio.']], 'warnings' => []],
        ]);
});

it('no escribe en el catálogo al validar', function () {
    validateBatch(batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\n"));

    expect(Campus::count())->toBe(0);
});

it('guarda la fila como vino y normalizada', function () {
    $batch = validateBatch(batchWithFile("code,name,modality,telefono\n c9 ,Campus   Nueve,presencial,555\n"));

    $row = $batch->rows()->sole();

    expect($row->raw)->toBe(['code' => ' c9 ', 'name' => 'Campus   Nueve', 'modality' => 'presencial', 'telefono' => '555'])
        ->and($row->normalized)->toBe(['code' => 'C9', 'name' => 'Campus Nueve', 'modality' => 'presencial']);
});

it('reconoce las columnas por campo o por etiqueta sin importar mayúsculas, acentos ni espacios', function () {
    $batch = validateBatch(batchWithFile(" CLAVE ,Nombre,MODALIDÁD\nC9,Campus Nueve,presencial\n"));

    expect($batch->rows()->sole()->normalized)->toBe(['code' => 'C9', 'name' => 'Campus Nueve', 'modality' => 'presencial'])
        ->and($batch->fresh()->valid_rows)->toBe(1);
});

it('usa el mapeo de columnas guardado del lote', function () {
    $mapping = ImportMapping::factory()->create(['template' => 'test-campuses', 'columns' => ['CVE' => 'code', 'DESC' => 'name', 'MOD' => 'modality']]);

    $batch = validateBatch(batchWithFile("CVE,DESC,MOD\nC9,Campus Nueve,presencial\n", attributes: ['mapping_id' => $mapping->id]));

    expect($batch->rows()->sole()->normalized)->toBe(['code' => 'C9', 'name' => 'Campus Nueve', 'modality' => 'presencial']);
});

it('marca como error la segunda fila con la misma clave natural y dice dónde está la primera', function () {
    $batch = validateBatch(batchWithFile("code,name,modality\nC9,Uno,presencial\nC8,Dos,presencial\nc9,Tres,presencial\n"));

    expect(validatedRows($batch)[4])->toBe([
        'status' => RowStatus::Error,
        'errors' => ['code' => ['La clave se repite: ya viene en la fila 2.']],
        'warnings' => [],
    ])
        ->and(validatedRows($batch)[2]['status'])->toBe(RowStatus::Valid)
        ->and($batch->fresh())
        ->valid_rows->toBe(2)
        ->error_rows->toBe(1);
});

it('una fila con advertencia queda como warning y cuenta como válida', function () {
    Campus::factory()->inactive()->create(['code' => 'C9']);

    $batch = validateBatch(batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\n"));

    expect(validatedRows($batch)[2])->toBe([
        'status' => RowStatus::Warning,
        'errors' => [],
        'warnings' => ['code' => ['El registro está inactivo: se actualizan sus datos, pero sigue inactivo.']],
    ])
        ->and($batch->fresh()->valid_rows)->toBe(1);
});

it('valida archivos de miles de filas', function () {
    $lines = collect(range(1, 1200))->map(fn (int $n): string => "K{$n},Campus {$n},presencial")->implode("\n");

    $batch = validateBatch(batchWithFile("code,name,modality\n{$lines}\n"));

    expect($batch->fresh())
        ->total_rows->toBe(1200)
        ->valid_rows->toBe(1200)
        ->and(ImportRow::count())->toBe(1200);
});

it('deja el lote fallido, sin filas, si falta la columna de la clave natural', function () {
    $batch = validateBatch(batchWithFile("name,modality\nCampus Nueve,presencial\n"));

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('Falta la columna de clave, que identifica cada registro.')
        ->and(ImportRow::count())->toBe(0);
});

it('deja el lote fallido con el motivo si el archivo no se puede leer', function () {
    $batch = validateBatch(batchWithFile('x', 'xls'));

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Failed)
        ->failure_reason->toBe('Guarda el archivo como .xlsx o .csv para importarlo.');
});

it('solo valida un lote recién subido', function () {
    $batch = batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\n", attributes: ['status' => BatchStatus::Validated]);

    expect(fn () => validateBatch($batch))->toThrow(LogicException::class, 'ya no está subido')
        ->and(ImportRow::count())->toBe(0);
});

it('deja una entrada de bitácora con el resultado y avisa con BatchValidated', function () {
    Event::fake([BatchValidated::class]);

    $batch = validateBatch(batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\nC8,,presencial\n", attributes: ['original_name' => 'campus.csv']));

    expect(Activity::sole())
        ->event->toBe('validated')
        ->description->toBe('Validó campus.csv para importar Campus (prueba): 1 de 2 filas válidas')
        ->and(Activity::sole()->subject->is($batch))->toBeTrue();

    Event::assertDispatched(BatchValidated::class, fn (BatchValidated $event): bool => $event->batch->is($batch));
});

it('un lote fallido también deja bitácora con el motivo y avisa', function () {
    Event::fake([BatchValidated::class]);

    $batch = validateBatch(batchWithFile('x', 'xls', ['original_name' => 'campus.xls']));

    expect(Activity::sole())
        ->event->toBe('failed')
        ->description->toBe('No se pudo validar campus.xls: Guarda el archivo como .xlsx o .csv para importarlo.');

    Event::assertDispatched(BatchValidated::class);
});

/**
 * Plantilla de prueba que truena al normalizar la clave BOOM, como un archivo que se corta a la mitad, y
 * anota cuántas filas llevaba leídas el lote al llegar a la clave PROBE.
 */
class ValidateProbeTemplate extends CampusesTemplate
{
    public static ?int $processedAtProbe = null;

    public function normalize(array $row): array
    {
        if (($row['code'] ?? null) === 'BOOM') {
            throw new RuntimeException('El archivo se cortó.');
        }

        if (($row['code'] ?? null) === 'PROBE') {
            self::$processedAtProbe = ImportBatch::query()->sole()->processed_rows;
        }

        return parent::normalize($row);
    }
}

it('lleva la cuenta de las filas leídas, bloque por bloque, para mostrar el avance', function () {
    app()->bind(CampusesTemplate::class, ValidateProbeTemplate::class);
    ValidateProbeTemplate::$processedAtProbe = null;
    $lines = collect(range(1, 700))->map(fn (int $n): string => $n === 650 ? 'PROBE,Campus,presencial' : "K{$n},Campus {$n},presencial")->implode("\n");

    $batch = validateBatch(batchWithFile("code,name,modality\n{$lines}\n"));

    // El lote que devuelve ya trae la cuenta, aunque cada bloque la sumó en la base.
    expect(ValidateProbeTemplate::$processedAtProbe)->toBe(500)
        ->and($batch->processed_rows)->toBe(700);
});

it('un lote que falla a la mitad no se queda con filas a medias', function () {
    app()->bind(CampusesTemplate::class, ValidateProbeTemplate::class);
    $lines = collect(range(1, 700))->map(fn (int $n): string => $n === 650 ? 'BOOM,x,presencial' : "K{$n},Campus {$n},presencial")->implode("\n");

    $batch = batchWithFile("code,name,modality\n{$lines}\n");

    expect(fn () => validateBatch($batch))->toThrow(RuntimeException::class, 'El archivo se cortó.')
        ->and($batch->fresh()->status)->toBe(BatchStatus::Failed)
        ->and(ImportRow::count())->toBe(0);
});

it('usa el mapeo del lote aunque después se haya desactivado o borrado', function () {
    $mapping = ImportMapping::factory()->create(['template' => 'test-campuses', 'columns' => ['CVE' => 'code', 'DESC' => 'name', 'MOD' => 'modality']]);
    $batch = batchWithFile("CVE,DESC,MOD\nC9,Campus Nueve,presencial\n", attributes: ['mapping_id' => $mapping->id]);
    $mapping->update(['is_active' => false]);
    $mapping->delete();

    expect(validateBatch($batch)->rows()->sole()->normalized['code'])->toBe('C9');
});

it('un texto con UTF-8 inválido es error de su fila y no tumba el lote', function () {
    // El lector convierte a UTF-8 todo archivo que no lo es; esto cubre una plantilla que lo produzca.
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function normalize(array $row): array
        {
            $row = parent::normalize($row);

            return $row['code'] === 'C8' ? [...$row, 'name' => "Ingenier\xEDa"] : $row;
        }
    });

    $batch = validateBatch(batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\nC8,Campus Ocho,presencial\n"));

    expect($batch->fresh())
        ->status->toBe(BatchStatus::Validated)
        ->valid_rows->toBe(1)
        ->error_rows->toBe(1)
        ->and(validatedRows($batch)[3]['errors'])
        ->toBe(['name' => ['El texto no es UTF-8 válido: guarda el archivo en UTF-8 o vuelve a exportarlo.']]);
});

it('la bitácora de la validación es de quien subió el lote', function () {
    $batch = validateBatch(batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\n"));

    expect(Activity::sole()->causer->is($this->admin))->toBeTrue();
});

it('una fila con error no cuenta como la primera de su clave', function () {
    $batch = validateBatch(batchWithFile("code,name,modality\nC9,,presencial\nC9,Campus Nueve,presencial\n"));

    expect(validatedRows($batch)[3]['status'])->toBe(RowStatus::Valid)
        ->and(validatedRows($batch)[2]['status'])->toBe(RowStatus::Error);
});

it('detecta la clave repetida aunque una venga como número y otra como texto', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function normalize(array $row): array
        {
            // La normalización base, sin las mayúsculas de CampusesTemplate: la clave numérica sigue siendo número.
            return ImportTemplate::normalize($row);
        }

        public function rules(): array
        {
            return [...parent::rules(), 'code' => ['required', 'max:10']];
        }
    });

    $batch = validateBatch(batchWithFile(campusXlsx([['code', 'name', 'modality'], [20111234, 'Uno', 'presencial'], ['20111234', 'Dos', 'presencial']]), 'xlsx'));

    expect(validatedRows($batch)[3]['errors'])->toBe(['code' => ['La clave se repite: ya viene en la fila 2.']]);
});

it('una columna con el nombre de un campo gana sobre la etiqueta de otro campo', function () {
    app()->bind(CampusesTemplate::class, fn () => new class extends CampusesTemplate
    {
        public function fields(): array
        {
            return ['code' => 'clave', 'name' => 'code', 'modality' => 'modalidad'];
        }
    });

    $batch = validateBatch(batchWithFile("code,name,modality\nC9,Campus Nueve,presencial\n"));

    expect($batch->rows()->sole()->normalized)->toBe(['code' => 'C9', 'name' => 'Campus Nueve', 'modality' => 'presencial']);
});
