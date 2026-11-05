<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| `imports:migrate-sii`: corre pasos en orden por el motor de importación existente (guardar, validar y
| aplicar un lote por plantilla), escribe un CSV intermedio por lote y un archivo de incidencias sin valores
| personales, y con --dry-run no deja rastro.
*/

/**
 * Paso de prueba: dos departamentos por la plantilla `departments`, más lo que la prueba le pida.
 */
final class FakeDepartmentsStep implements SiiStep
{
    /** @var list<array<string, string|null>> */
    public static array $extraRows = [];

    public function name(): string
    {
        return 'departamentos';
    }

    public function batches(SiiMigration $migration): iterable
    {
        yield new SiiBatch('departments', 'departamentos.csv', [
            ['code' => 'AAA', 'name' => 'Departamento A'],
            ['code' => 'BBB', 'name' => 'Departamento B'],
            ...self::$extraRows,
        ], incidents: [['level' => 'warning', 'ref' => 'x1', 'message' => 'Aviso del paso.']]);
    }
}

beforeEach(function () {
    Storage::fake('imports');
    FakeDepartmentsStep::$extraRows = [];
    app()->instance('sii.steps', [FakeDepartmentsStep::class]);

    $this->dir = sys_get_temp_dir().'/sii-'.uniqid();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    $this->admin = User::factory()->admin()->create(['username' => 'migrador']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('migra un paso y deja su CSV intermedio', function () {
    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    expect(Department::count())->toBe(2)
        ->and(file_get_contents("{$this->dir}/_nexus/departamentos.csv"))->toStartWith("clave,nombre\n")
        ->and(ImportBatch::count())->toBe(1);
    Storage::disk('imports')->assertExists(ImportBatch::sole()->file_path);
});

it('--dry-run no deja rastro', function () {
    Queue::fake();

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--dry-run' => true])->assertSuccessful();

    expect(Department::count())->toBe(0)
        ->and(ImportBatch::count())->toBe(0)
        ->and(Storage::disk('imports')->allFiles())->toBe([])
        ->and(file_exists("{$this->dir}/_incidencias.csv"))->toBeTrue();
    Queue::assertNothingPushed();
});

it('repetir la migración no cambia nada', function () {
    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();
    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $second = ImportBatch::query()->latest('created_at')->latest('id')->first();

    expect(Department::count())->toBe(2)
        ->and([$second->created_rows, $second->updated_rows])->toBe([0, 0]);
});

it('escribe las incidencias sin datos personales', function () {
    FakeDepartmentsStep::$extraRows = [['code' => 'CCC', 'name' => null]];

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), file("{$this->dir}/_incidencias.csv", FILE_IGNORE_NEW_LINES));

    expect($rows[0])->toBe(['paso', 'archivo', 'fila', 'campo', 'nivel', 'mensaje'])
        ->and($rows)->toContain(['departamentos', 'departamentos.csv', '4', 'name', 'error', 'El campo nombre es obligatorio.'])
        ->and($rows)->toContain(['departamentos', 'departamentos.csv', 'x1', '', 'warning', 'Aviso del paso.'])
        ->and(Department::count())->toBe(2);
});

it('se detiene antes de escribir si el contrato no cuadra', function () {
    File::put("{$this->dir}/organigrama.csv", "otra,cosa\n1,2\n");

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertFailed();

    expect(Department::count())->toBe(0)
        ->and(ImportBatch::count())->toBe(0)
        ->and(is_dir("{$this->dir}/_nexus"))->toBeFalse();
});

it('exige un usuario con permiso de importar', function () {
    User::factory()->create(['username' => 'sinpermiso']);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir])->assertFailed();
    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'nadie'])->assertFailed();
    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'sinpermiso'])->assertFailed();

    expect(Department::count())->toBe(0)
        ->and(ImportBatch::count())->toBe(0)
        ->and(is_dir("{$this->dir}/_nexus"))->toBeFalse();
});

it('rechaza un campus desconocido antes de escribir', function () {
    app()->instance('sii.steps', [UnknownCampusStep::class]);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertFailed();

    expect(ImportBatch::count())->toBe(0);
});

final class UnknownCampusStep implements SiiStep
{
    public function name(): string
    {
        return 'campus';
    }

    public function batches(SiiMigration $migration): iterable
    {
        yield new SiiBatch('departments', 'departamentos.csv', [['code' => 'AAA', 'name' => 'A']], campus: 'ZZ');
    }
}
