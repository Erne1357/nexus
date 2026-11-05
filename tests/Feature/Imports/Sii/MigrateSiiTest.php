<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

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
        ->and(file_get_contents("{$this->dir}/_nexus/departamentos.csv"))->toStartWith("clave,nombre,activo\n")
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

it('rechaza un campus desconocido y dice qué lote se detuvo', function () {
    app()->instance('sii.steps', [FakeDepartmentsStep::class, UnknownCampusStep::class]);

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('paso campus')->toContain('lotes anteriores ya se aplicaron')
        ->and(Department::count())->toBe(2);
});

it('en un ensayo un campus desconocido no deja nada', function () {
    app()->instance('sii.steps', [FakeDepartmentsStep::class, UnknownCampusStep::class]);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--dry-run' => true])->assertFailed();

    expect(Department::count())->toBe(0)->and(ImportBatch::count())->toBe(0);
});

it('no imprime en consola el mensaje de una excepción inesperada', function () {
    app()->instance('sii.steps', [ExplodingStep::class]);

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->not->toContain('VALOR-PERSONAL-123')
        ->and($output)->toContain('RuntimeException')->toContain('paso explota');
});

it('el log de una falla trae la clase, el lugar y el SQLSTATE, nunca el mensaje ni los valores', function () {
    app()->instance('sii.steps', [QueryFailingStep::class]);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode(array_map(
            fn (mixed $value): mixed => $value instanceof Throwable ? [$value::class, $value->getMessage(), $value instanceof QueryException ? $value->getBindings() : null] : $value,
            $event->context,
        ));
    });

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);
    $text = implode("\n", $logged);

    expect($exit)->toBe(1)
        ->and($logged)->not->toBeEmpty()
        ->and($text)->not->toContain('VALOR-PERSONAL-555')
        ->toContain('QueryException')->toContain('23505')->toContain('MigrateSiiTest.php:');
});

it('restaura la cola después de un ensayo', function () {
    $before = config('queue.default');

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--dry-run' => true])->assertSuccessful();

    expect(config('queue.default'))->toBe($before);
});

it('se niega a correr en producción sin --force', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertFailed();
    expect(Department::count())->toBe(0);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--force' => true])->assertSuccessful();
    expect(Department::count())->toBe(2);
});

it('falla con un nombre de paso desconocido sin escribir nada', function () {
    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--only' => ['nada']]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('departamentos')
        ->and(is_dir("{$this->dir}/_nexus"))->toBeFalse()
        ->and(file_exists("{$this->dir}/_incidencias.csv"))->toBeFalse()
        ->and(Department::count())->toBe(0);
});

it('reescribe las incidencias aunque no haya ninguna', function () {
    File::put("{$this->dir}/_incidencias.csv", "viejo\n");
    app()->instance('sii.steps', []);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    expect(file_get_contents("{$this->dir}/_incidencias.csv"))->toBe("paso,archivo,fila,campo,nivel,mensaje\n");
});

it('las incidencias no traen el valor crudo de la fila', function () {
    FakeDepartmentsStep::$extraRows = [['code' => 'ZZZ-VALOR-PERSONAL-9999', 'name' => 'Ok']];

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $text = file_get_contents("{$this->dir}/_incidencias.csv");
    expect($text)->not->toContain('VALOR-PERSONAL')->toContain('departamentos.csv,4,code,error');
});

it('un fallo de limpieza no tapa el motivo original ni se imprime crudo', function () {
    app()->instance('sii.steps', [ExplodingStep::class]);
    mkdir("{$this->dir}/_incidencias.csv");

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('paso explota')->not->toContain('VALOR-PERSONAL-123')
        ->not->toContain('fopen')->not->toContain('Failed to open');
});

it('un fallo de limpieza sin otro error sale con mensaje fijo', function () {
    mkdir("{$this->dir}/_incidencias.csv");

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);
    $output = Artisan::output();

    expect($exit)->toBe(1)->and($output)->toContain('limpieza')->not->toContain('fopen')->not->toContain('Failed to open');
});

it('reporta una vez por archivo los renglones malformados, reparados y la anotación al final, sin su contenido', function () {
    app()->instance('sii.steps', [ReadingStep::class, FakeDepartmentsStep::class]);
    $blankNumbers = ['promedio_periodo_anterior' => '', 'creditos_aprobados' => '', 'creditos_cursados' => '', 'promedio_final_alcanzado' => '', 'indice_reprobacion_acumulado' => ''];
    SiiFixtures::write("{$this->dir}/alumnos_todos.csv", SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos),
        "'VALOR-PERSONAL-77','ISC'",
        SiiFixtures::line(SiiFile::Alumnos, [...$blankNumbers, 'promedio_aritmetico_acumulado' => '87,50']),
        '',
        "'Subtotal','VALOR-PERSONAL-88'",
        SiiFixtures::line(SiiFile::Alumnos),
        '',
        "'Total de registros','3'",
    ]);

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);
    $output = Artisan::output();
    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), file("{$this->dir}/_incidencias.csv", FILE_IGNORE_NEW_LINES));
    $reads = array_values(array_filter($rows, fn (array $row): bool => $row[1] === 'alumnos_todos.csv'));

    expect($exit)->toBe(0)
        ->and(array_map(fn (array $row): array => array_slice($row, 0, 5), $reads))->toBe([
            ['lee', 'alumnos_todos.csv', 'malformados', '', 'error'],
            ['lee', 'alumnos_todos.csv', 'reparados', '', 'warning'],
            ['lee', 'alumnos_todos.csv', 'anotación al final', '', 'warning'],
        ])
        ->and($reads[0][5])->toContain('2 renglón(es)')->toEndWith('Líneas 3, 6.')
        ->and($reads[1][5])->toEndWith('Líneas 4.')
        ->and($reads[2][5])->toEndWith('Líneas 9.')
        ->and(file_get_contents("{$this->dir}/_incidencias.csv"))->not->toContain('VALOR-PERSONAL')
        ->and($output)->toContain('2 renglones del SII no se pudieron leer')->not->toContain('VALOR-PERSONAL');
});

it('cuenta los lotes anteriores aplicados cuando un lote posterior falla', function () {
    app()->instance('sii.steps', [FakeDepartmentsStep::class, BadTemplateStep::class]);

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('ya se aplicaron (1)');
});

/**
 * Paso de prueba que lee completo dos veces el archivo de alumnos (como carreras y alumnos) y no entrega lotes.
 */
final class ReadingStep implements SiiStep
{
    public function name(): string
    {
        return 'lee';
    }

    public function batches(SiiMigration $migration): iterable
    {
        iterator_to_array($migration->export()->reader(SiiFile::Alumnos)->rows());
        iterator_to_array($migration->export()->reader(SiiFile::Alumnos)->rows(['no_de_control']));

        return [];
    }
}

final class BadTemplateStep implements SiiStep
{
    public function name(): string
    {
        return 'mala';
    }

    public function batches(SiiMigration $migration): iterable
    {
        yield new SiiBatch('no_existe', 'x.csv', [['code' => 'A']]);
    }
}

/**
 * Paso de prueba que falla como una escritura rechazada por la base, con un valor personal en el SQL y en el mensaje.
 */
final class QueryFailingStep implements SiiStep
{
    public function name(): string
    {
        return 'consulta';
    }

    public function batches(SiiMigration $migration): iterable
    {
        $pdo = new PDOException('SQLSTATE[23505]: Unique violation: Key (rfc)=(VALOR-PERSONAL-555) already exists.');
        $pdo->errorInfo = ['23505', 7, 'Key (rfc)=(VALOR-PERSONAL-555) already exists.'];

        throw new QueryException('pgsql', 'insert into teachers (rfc) values (?)', ['VALOR-PERSONAL-555'], $pdo);
        yield;
    }
}

final class ExplodingStep implements SiiStep
{
    public function name(): string
    {
        return 'explota';
    }

    public function batches(SiiMigration $migration): iterable
    {
        throw new RuntimeException('VALOR-PERSONAL-123');
        yield;
    }
}

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
