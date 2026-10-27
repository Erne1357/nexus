<?php

use App\Modules\Imports\Actions\ExportBatchErrors;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| ExportBatchErrors escribe en el disco `imports` el CSV con las filas con error de un lote, para que
| el admin las corrija y vuelva a subir el archivo (reglas-de-negocio.md, "Reglas de datos"). Cada fila
| sale como vino, con su número al principio y sus errores al final con la etiqueta del campo. Lo
| escribe la cola al terminar de validar o de aplicar; la página solo lo descarga.
*/

beforeEach(function () {
    Storage::fake('imports');
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->batch = ImportBatch::factory()->create();
});

function exportedBatchErrors(ImportBatch $batch): string
{
    app(ExportBatchErrors::class)->handle($batch);

    return Storage::disk('imports')->get($batch->errors_path);
}

it('escribe las filas con error como vinieron, con su fila y sus errores, en UTF-8 para Excel', function () {
    ImportRow::factory()->for($this->batch, 'batch')->create([
        'row_number' => 4,
        'raw' => ['CVE' => 'C8', 'DESC' => 'Ocho, "el nuevo"'],
        'errors' => ['name' => ['Falta.'], 'modality' => ['No existe.', 'Otra.']],
        'status' => RowStatus::Error,
    ]);
    ImportRow::factory()->for($this->batch, 'batch')->create([
        'row_number' => 2,
        'raw' => ['CVE' => 'Ñ1', 'DESC' => null],
        'errors' => ['code' => ['La clave se repite: ya viene en la fila 1.']],
        'status' => RowStatus::Error,
    ]);
    ImportRow::factory()->for($this->batch, 'batch')->create(['row_number' => 3, 'raw' => ['CVE' => 'OK', 'DESC' => 'Bien'], 'status' => RowStatus::Valid]);

    expect(exportedBatchErrors($this->batch))->toBe(
        "\u{FEFF}\"Fila del archivo\",CVE,DESC,\"Errores en Nexus\"\n"
        ."2,Ñ1,,\"clave: La clave se repite: ya viene en la fila 1.\"\n"
        ."4,C8,\"Ocho, \"\"el nuevo\"\"\",\"nombre: Falta. modalidad: No existe. Otra.\"\n"
    );
});

it('un texto que Excel tomaría por fórmula sale como texto; los números negativos quedan igual', function () {
    ImportRow::factory()->for($this->batch, 'batch')->create([
        'row_number' => 2,
        'raw' => ['A' => '=HYPERLINK("http://x","y")', 'B' => '+A1', 'C' => '@SUM(A1)', 'D' => '-5', 'E' => '-x'],
        'errors' => ['code' => ['Mal.']],
        'status' => RowStatus::Error,
    ]);

    expect(exportedBatchErrors($this->batch))->toContain("2,\"'=HYPERLINK(\"\"http://x\"\",\"\"y\"\")\",'+A1,'@SUM(A1),-5,'-x,");
});

it('sin filas con error deja solo el encabezado y reemplaza un archivo anterior', function () {
    Storage::disk('imports')->put($this->batch->errors_path, 'viejo');

    expect(exportedBatchErrors($this->batch))->toBe("\u{FEFF}\"Fila del archivo\",\"Errores en Nexus\"\n");
});
