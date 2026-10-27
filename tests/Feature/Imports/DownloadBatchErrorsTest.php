<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;

/*
| Descarga de los errores de un lote (`/imports/batches/{id}/errors`): entrega el CSV que dejó la cola
| (ExportBatchErrors) con el nombre del archivo original. Como todo lo de importación, solo para el
| admin (fila "Importar catálogos").
*/

beforeEach(function () {
    Storage::fake('imports');
    $this->admin = User::factory()->admin()->create();
    $this->batch = ImportBatch::factory()->create(['original_name' => 'campus 2026.csv']);
    Storage::disk('imports')->put($this->batch->errors_path, "\u{FEFF}\"Fila del archivo\",\"Errores en Nexus\"\n");
    $this->batch->update(['errors_exported_at' => now()]);
});

it('descarga el CSV de errores con el nombre del archivo original', function () {
    $response = $this->actingAs($this->admin)->get(route('imports.batch-errors', $this->batch->id));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('campus 2026 - errores.csv');
});

it('responde 404 mientras la cola no termina el archivo o mientras lo rehace al aplicar', function () {
    Storage::disk('imports')->delete($this->batch->errors_path);

    $this->actingAs($this->admin)->get(route('imports.batch-errors', $this->batch->id))->assertNotFound();

    Storage::disk('imports')->put($this->batch->errors_path, 'el de la validación');
    $this->batch->update(['errors_exported_at' => null]);

    $this->actingAs($this->admin)->get(route('imports.batch-errors', $this->batch->id))->assertNotFound();
});

it('solo deja descargar al admin, y a nadie más le dice si el lote existe', function (Role $role, array $scope) {
    $campus = Campus::factory()->create();
    $user = User::factory()->withRole($role, ['campus_id' => $campus->id, ...$scope])->create();

    $this->actingAs($user)->get(route('imports.batch-errors', $this->batch->id))->assertForbidden();
    $this->actingAs($user)->get(route('imports.batch-errors', '01900000-0000-7000-8000-000000000000'))->assertForbidden();
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
