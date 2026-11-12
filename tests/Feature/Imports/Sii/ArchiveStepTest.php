<?php

use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ArchiveSiiExport;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\Steps\ArchiveStep;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `archivo` de imports:migrate-sii (decisión del 10 de noviembre de 2026, confirmada): guarda la exportación
| original del SII, renglón por renglón, en el esquema `sii_archivo` (una tabla por archivo, con `_linea` y las
| columnas del contrato), sin el NIP del alumno ni fotos ni firmas, y la reemplaza completa en cada corrida.
*/

/**
 * @return list<string>
 */
function siiArchiveTables(): array
{
    return DB::table('pg_tables')->where('schemaname', 'sii_archivo')->orderBy('tablename')->pluck('tablename')->all();
}

/**
 * @return list<string>
 */
function siiArchiveColumns(string $table): array
{
    return DB::table('information_schema.columns')
        ->where('table_schema', 'sii_archivo')->where('table_name', $table)
        ->orderBy('ordinal_position')->pluck('column_name')->all();
}

/**
 * @return list<array<string, int|string|null>>
 */
function siiArchiveRows(string $table): array
{
    return DB::table("sii_archivo.{$table}")->orderBy('_linea')->get()
        ->map(fn (object $row): array => ['_linea' => (int) $row->_linea] + (array) $row)
        ->all();
}

/**
 * Todo el texto del esquema `sii_archivo`, tabla por tabla, para buscar un valor en cualquier columna.
 */
function siiArchiveDump(): string
{
    return implode("\n", array_map(
        fn (string $table): string => (string) DB::scalar("select string_agg(t::text, '|') from sii_archivo.\"{$table}\" t"),
        siiArchiveTables(),
    ));
}

beforeEach(function () {
    Storage::fake('imports');
    User::factory()->admin()->create(['username' => 'migrador']);

    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);

    $this->present = ['alumnos_todos', 'aulas', 'carreras', 'docentes', 'docentes2', 'especialidades', 'grupos', 'horarios', 'materias', 'materias_carreras', 'materias_claves_tecnm', 'organigrama'];
    $this->students = function (int $count): void {
        SiiFixtures::write("{$this->dir}/alumnos_todos.csv", SiiFile::Alumnos, array_map(fn (int $i): string => SiiFixtures::line(SiiFile::Alumnos, [
            'no_de_control' => "'C00{$i}'", 'nip' => "'NIP-SECRETO-{$i}'", 'foto' => "'FOTO-ALUMNO-{$i}'", 'firma' => "'FIRMA-ALUMNO-{$i}'",
        ]), range(1, $count)));
    };
    ($this->students)(3);
    SiiFixtures::writeStaff($this->dir, [
        ['rfc' => 'PRUE800101AB1', 'foto' => 'FOTO-DOCENTE-1', 'firma' => 'FIRMA-DOCENTE-1'],
        ['rfc' => 'PRUE800101AB2', 'foto' => 'FOTO-DOCENTE-2', 'firma' => 'FIRMA-DOCENTE-2'],
    ]);

    $this->archive = fn (array $options = []): int => Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--only' => ['archivo'], ...$options]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('guarda una tabla por archivo presente, con _linea y las columnas del contrato en su orden, sin correr otros pasos', function () {
    expect(($this->archive)())->toBe(0);

    expect(siiArchiveTables())->toBe(['_cargas', ...$this->present])
        ->and(ImportBatch::count())->toBe(0);

    foreach ($this->present as $value) {
        $file = SiiFile::from($value);
        $excluded = match ($file) {
            SiiFile::Alumnos => ['nip', 'foto', 'firma'],
            SiiFile::Docentes => ['foto', 'firma'],
            default => [],
        };

        expect(siiArchiveColumns($value))->toBe(['_linea', ...array_values(array_diff($file->headers(), $excluded))], $value);
    }

    expect(DB::table('sii_archivo.alumnos_todos')->count())->toBe(3)
        ->and(DB::table('sii_archivo.docentes')->count())->toBe(2)
        ->and(DB::table('sii_archivo.aulas')->count())->toBe(1);
});

it('alumnos_todos no guarda el NIP, la foto ni la firma, ni como columna ni como valor', function () {
    ($this->archive)();

    expect(siiArchiveColumns('alumnos_todos'))->not->toContain('nip')->not->toContain('foto')->not->toContain('firma')
        ->and(siiArchiveDump())->toContain('C001')
        ->not->toContain('NIP-SECRETO')->not->toContain('FOTO-ALUMNO')->not->toContain('FIRMA-ALUMNO');
});

it('docentes no guarda la foto ni la firma, ni como columna ni como valor', function () {
    ($this->archive)();

    expect(siiArchiveColumns('docentes'))->not->toContain('foto')->not->toContain('firma')
        ->and(siiArchiveDump())->toContain('PRUE800101AB1')
        ->not->toContain('FOTO-DOCENTE')->not->toContain('FIRMA-DOCENTE');
});

it('guarda los valores tal cual los entrega el lector, con su número de línea y el vacío como cadena vacía', function () {
    $blankNumbers = ['promedio_periodo_anterior' => '', 'creditos_aprobados' => '', 'creditos_cursados' => '', 'promedio_final_alcanzado' => '', 'indice_reprobacion_acumulado' => ''];
    SiiFixtures::write("{$this->dir}/alumnos_todos.csv", SiiFile::Alumnos, [
        SiiFixtures::line(SiiFile::Alumnos, [
            'no_de_control' => "'C001'", 'nombre_alumno' => "'ANA\tMARIA'", 'apellido_paterno' => "'C:\\RUTA\\N'", 'apellido_materno' => "'\\N'",
            'escuela_procedencia' => "'  CON ESPACIOS  '", 'correo_electronico' => "''", 'ciudad_procedencia' => "'CD. JU\xC1REZ'",
        ]),
        "'VALOR-ILEGIBLE','ISC'",
        SiiFixtures::line(SiiFile::Alumnos, [...$blankNumbers, 'no_de_control' => "'C002'", 'promedio_aritmetico_acumulado' => '87,50']),
    ]);

    ($this->archive)();

    $expected = [];
    foreach (SiiExport::open($this->dir)->reader(SiiFile::Alumnos)->rows(ArchiveSiiExport::columns(SiiFile::Alumnos)) as $line => $row) {
        $expected[] = ['_linea' => $line, ...$row];
    }
    $rows = siiArchiveRows('alumnos_todos');

    expect($rows)->toBe($expected)
        ->and(array_column($rows, '_linea'))->toBe([2, 4])
        ->and($rows[0]['nombre_alumno'])->toBe("ANA\tMARIA")
        ->and($rows[0]['apellido_paterno'])->toBe('C:\\RUTA\\N')
        ->and($rows[0]['apellido_materno'])->toBe('\\N')
        ->and($rows[0]['escuela_procedencia'])->toBe('CON ESPACIOS')
        ->and($rows[0]['correo_electronico'])->toBe('')
        ->and($rows[0]['ciudad_procedencia'])->toBe('CD. JUÁREZ')
        ->and($rows[1]['promedio_aritmetico_acumulado'])->toBe('87.50')
        ->and($rows[1]['creditos_aprobados'])->toBe('');
});

it('una segunda corrida reemplaza el archivo sin duplicar y borra la tabla de un archivo que ya no vino', function () {
    SiiFixtures::write("{$this->dir}/historia_alumno.csv", SiiFile::HistoriaAlumno, [
        SiiFixtures::line(SiiFile::HistoriaAlumno, ['no_de_control' => "'C001'"]),
        SiiFixtures::line(SiiFile::HistoriaAlumno, ['no_de_control' => "'C002'"]),
    ]);

    ($this->archive)();

    expect(siiArchiveTables())->toContain('historia_alumno')
        ->and(DB::table('sii_archivo.historia_alumno')->count())->toBe(2);

    unlink("{$this->dir}/historia_alumno.csv");
    ($this->students)(2);

    expect(($this->archive)())->toBe(0);

    expect(siiArchiveTables())->toBe(['_cargas', ...$this->present])
        ->and(DB::table('sii_archivo.alumnos_todos')->orderBy('_linea')->pluck('no_de_control')->all())->toBe(['C001', 'C002'])
        ->and(DB::table('sii_archivo.docentes')->count())->toBe(2)
        ->and(DB::table('sii_archivo._cargas')->pluck('archivo')->all())->not->toContain('historia_alumno');
});

it('_cargas dice qué archivo se cargó, con cuántos renglones, qué columnas se excluyeron y de qué carpeta', function () {
    rename("{$this->dir}/aulas.csv", "{$this->dir}/Aulas.CSV");
    file_put_contents("{$this->dir}/alumnos_todos.csv", "'VALOR-ILEGIBLE','ISC'\r\n", FILE_APPEND);

    ($this->archive)();

    $loads = DB::table('sii_archivo._cargas')->get()->keyBy('archivo');

    expect($loads->keys()->sort()->values()->all())->toBe($this->present)
        ->and((int) $loads['alumnos_todos']->renglones)->toBe(3)
        ->and((int) $loads['alumnos_todos']->ilegibles)->toBe(1)
        ->and($loads['alumnos_todos']->columnas_excluidas)->toBe('{foto,firma,nip}')
        ->and($loads['alumnos_todos']->nombre_en_carpeta)->toBe('alumnos_todos.csv')
        ->and($loads['alumnos_todos']->carpeta)->toBe($this->dir)
        ->and($loads['alumnos_todos']->cargado_en)->not->toBeNull()
        ->and((int) $loads['docentes']->renglones)->toBe(2)
        ->and($loads['docentes']->columnas_excluidas)->toBe('{foto,firma}')
        ->and($loads['aulas']->nombre_en_carpeta)->toBe('Aulas.CSV')
        ->and($loads['aulas']->columnas_excluidas)->toBe('{}')
        ->and((int) $loads['aulas']->ilegibles)->toBe(0);
});

it('--dry-run no escribe en el archivo y dice cuántos renglones cargaría', function () {
    ($this->archive)();
    ($this->students)(1);
    SiiFixtures::write("{$this->dir}/historia_alumno.csv", SiiFile::HistoriaAlumno, [SiiFixtures::line(SiiFile::HistoriaAlumno)]);

    $counts = app(ArchiveSiiExport::class)->handle(SiiExport::open($this->dir), dryRun: true);

    expect($counts['alumnos_todos'])->toBe(1)
        ->and($counts['historia_alumno'])->toBe(1)
        ->and(siiArchiveTables())->not->toContain('historia_alumno')
        ->and(DB::table('sii_archivo.alumnos_todos')->count())->toBe(3)
        ->and((int) DB::table('sii_archivo._cargas')->where('archivo', 'alumnos_todos')->value('renglones'))->toBe(3);

    // El ensayo se revierte al final de todos modos: para ver que el paso ni siquiera escribe, se miran sus sentencias.
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    expect(($this->archive)(['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toMatch('/sii_archivo\s*\|\s*alumnos_todos\.csv\s*\|\s*1\s*\|/')
        ->toMatch('/sii_archivo\s*\|\s*historia_alumno\.csv\s*\|\s*1\s*\|/')
        ->and(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'sii_archivo')))->toBe([])
        ->and(siiArchiveTables())->not->toContain('historia_alumno')
        ->and(DB::table('sii_archivo.alumnos_todos')->count())->toBe(3);
});

it('si la carga falla a la mitad, queda el archivo anterior intacto', function () {
    ($this->archive)();
    ($this->students)(1);
    $export = SiiExport::open($this->dir);
    unlink("{$this->dir}/horarios.csv");

    expect(fn () => app(ArchiveSiiExport::class)->handle($export))->toThrow(RuntimeException::class);

    expect(siiArchiveTables())->toBe(['_cargas', ...$this->present])
        ->and(DB::table('sii_archivo.alumnos_todos')->count())->toBe(3)
        ->and((int) DB::table('sii_archivo._cargas')->where('archivo', 'alumnos_todos')->value('renglones'))->toBe(3)
        ->and(DB::table('sii_archivo._cargas')->count())->toBe(count($this->present));
});

it('va primero en la corrida: el original queda guardado aunque el paso siguiente se detenga', function () {
    expect(ImportsServiceProvider::SII_STEPS[0])->toBe(ArchiveStep::class);

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador']);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('en el paso organigrama')
        ->and(siiArchiveTables())->toBe(['_cargas', ...$this->present])
        ->and(DB::table('sii_archivo.alumnos_todos')->count())->toBe(3);
});

it('acceso no guarda la contraseña (hash MD5) ni como columna ni como valor, y permisos se guarda completo', function () {
    $hash = 'd41d8cd98f00b204e9800998ecf8427e';
    file_put_contents("{$this->dir}/acceso.csv", mb_convert_encoding(
        "usuario,nombre_usuario,contrasena,tipo_usuario,status\r\n"
        ."'DOCE800101AB1  ','MAR\u{00CD}A P\u{00C9}REZ INVENTADA  ','{$hash}  ','D ','A'\r\n"
        ."'usuario1        ','USUARIO UNO  ','{$hash}  ','J ','B'\r\n",
        'ISO-8859-1', 'UTF-8',
    ));
    SiiFixtures::write("{$this->dir}/permisos.csv", SiiFile::Permisos, [
        "'DOCE800101AB1  ','110401  ','ISC','SII'",
        "'usuario1  ','110402  ',1,'SII'",
    ]);

    expect(($this->archive)())->toBe(0);

    expect(siiArchiveTables())->toBe(['_cargas', 'acceso', ...$this->present, 'permisos'])
        ->and(siiArchiveColumns('acceso'))->toBe(['_linea', 'usuario', 'nombre_usuario', 'tipo_usuario', 'status'])
        ->and(siiArchiveDump())->not->toContain($hash)
        ->and(siiArchiveRows('acceso'))->toBe([
            ['_linea' => 2, 'usuario' => 'DOCE800101AB1', 'nombre_usuario' => 'MARÍA PÉREZ INVENTADA', 'tipo_usuario' => 'D', 'status' => 'A'],
            ['_linea' => 3, 'usuario' => 'usuario1', 'nombre_usuario' => 'USUARIO UNO', 'tipo_usuario' => 'J', 'status' => 'B'],
        ])
        ->and(siiArchiveColumns('permisos'))->toBe(['_linea', ...SiiFile::Permisos->headers()])
        ->and(siiArchiveRows('permisos'))->toBe([
            ['_linea' => 2, 'usuario' => 'DOCE800101AB1', 'clave_area' => '110401', 'carrera' => 'ISC', 'aplicacion' => 'SII'],
            ['_linea' => 3, 'usuario' => 'usuario1', 'clave_area' => '110402', 'carrera' => '1', 'aplicacion' => 'SII'],
        ]);

    $loads = DB::table('sii_archivo._cargas')->get()->keyBy('archivo');

    expect($loads['acceso']->columnas_excluidas)->toBe('{contrasena}')
        ->and((int) $loads['acceso']->renglones)->toBe(2)
        ->and($loads['permisos']->columnas_excluidas)->toBe('{}');
});

it('sin acceso ni permisos la carga funciona y no crea sus tablas', function () {
    expect(($this->archive)())->toBe(0)
        ->and(siiArchiveTables())->not->toContain('acceso')->not->toContain('permisos');
});

it('--dry-run cuenta acceso y permisos sin escribirlos', function () {
    SiiFixtures::write("{$this->dir}/acceso.csv", SiiFile::Acceso, [SiiFixtures::line(SiiFile::Acceso, ['contrasena' => "'d41d8cd98f00b204e9800998ecf8427e'"])]);
    SiiFixtures::write("{$this->dir}/permisos.csv", SiiFile::Permisos, [SiiFixtures::line(SiiFile::Permisos), SiiFixtures::line(SiiFile::Permisos)]);

    $counts = app(ArchiveSiiExport::class)->handle(SiiExport::open($this->dir), dryRun: true);

    expect($counts['acceso'])->toBe(1)
        ->and($counts['permisos'])->toBe(2)
        ->and(siiArchiveTables())->not->toContain('acceso')->not->toContain('permisos');
});
