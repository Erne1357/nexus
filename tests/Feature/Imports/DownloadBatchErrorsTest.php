<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\TemplateRegistry;
use Tests\Fixtures\ImportTemplates\CampusesTemplate;

/*
| Descarga de los errores de un lote (`/imports/batches/{id}/errors`): un CSV con las filas con error
| como vinieron en el archivo, para que el admin las corrija y vuelva a subir el archivo
| (reglas-de-negocio.md, "Reglas de datos"). Primero el número de fila y al final los errores, con la
| etiqueta de su campo. Como todo lo de importación, solo para el admin (fila "Importar catálogos").
*/

beforeEach(function () {
    app()->instance(TemplateRegistry::class, new TemplateRegistry([CampusesTemplate::class]));
    $this->admin = User::factory()->admin()->create();
    $this->batch = ImportBatch::factory()->create(['original_name' => 'campus 2026.csv']);
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
});

it('descarga las filas con error como vinieron, con su fila y sus errores, en UTF-8 para Excel', function () {
    $response = $this->actingAs($this->admin)->get(route('imports.batch-errors', $this->batch->id));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('campus 2026 - errores.csv');

    expect($response->streamedContent())->toBe(
        "\u{FEFF}Fila,CVE,DESC,Errores\n"
        ."2,Ñ1,,\"clave: La clave se repite: ya viene en la fila 1.\"\n"
        ."4,C8,\"Ocho, \"\"el nuevo\"\"\",\"nombre: Falta. modalidad: No existe. Otra.\"\n"
    );
});

it('solo deja descargar al admin', function (Role $role, array $scope) {
    $campus = Campus::factory()->create();
    $user = User::factory()->withRole($role, ['campus_id' => $campus->id, ...$scope])->create();

    $this->actingAs($user)->get(route('imports.batch-errors', $this->batch->id))->assertForbidden();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'coordinador' => [Role::Coordinador, ['career_id' => fakeKey(20)]],
    'jefe de departamento' => [Role::JefeDepartamento, ['department_id' => fakeKey(10)]],
    'docente' => [Role::Docente, []],
]);

it('manda al login a quien no tiene sesión', function () {
    $this->get(route('imports.batch-errors', $this->batch->id))->assertRedirect(route('login'));
});

it('responde 404 a un lote que no existe o a un id que no es uuid', function (string $id) {
    $this->actingAs($this->admin)->get(route('imports.batch-errors', $id))->assertNotFound();
})->with(['otro uuid' => '01900000-0000-7000-8000-000000000000', 'no es uuid' => '1 OR 1=1']);
