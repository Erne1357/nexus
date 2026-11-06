<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\Steps\CareersStep;
use App\Modules\Imports\Sii\Steps\OrgChartStep;
use App\Modules\Imports\Sii\Steps\SubjectsStep;
use App\Modules\Imports\Sii\Steps\TeacherHistoryStep;
use App\Modules\Imports\Sii\Steps\TeachersStep;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `docentes` de imports:migrate-sii (D4, D9, D10): unión de docentes y docentes2 por RFC, solo nombramiento D o
| con grupos, el RFC viejo de estatus 04 se funde con el nuevo, activo por el periodo vigente, departamento de la
| adscripción o de los grupos recientes. Todo con datos sintéticos (RFC inventados, nada del SII real).
*/

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function teacherStaffRow(string $rfc, array $overrides = []): array
{
    return [
        'rfc' => $rfc, 'clave_area' => '110100', 'nombramiento' => 'D', 'status_empleado' => '01',
        'nombre_empleado' => 'JUAN', 'apellidos_empleado' => 'PEREZ DE LA CRUZ', 'correo_electronico' => '', 'inicio_plantel' => '000000',
        ...$overrides,
    ];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function teacherStaff2Row(string $rfc, array $overrides = []): array
{
    return [
        'rfc' => $rfc, 'apellidop' => 'GOMEZ', 'apellidom' => 'LUNA', 'nombre_empleado' => 'MARIA', 'id_unidad_organica' => '110100',
        'fecha_ingreso_institucion' => '', ...$overrides,
    ];
}

/**
 * Corre el paso con el mapa de departamentos armado a mano (llaves numéricas, como lo deja el organigrama).
 *
 * @return array{migration: SiiMigration, rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}
 */
function teachersStepRun(string $dir): array
{
    $migration = new SiiMigration(SiiExport::open($dir), '/nonexistent');
    $migration->remember('department_for_area', fn (): array => [110100 => '110100', 110600 => '110600', 999999 => null]);
    $out = ['migration' => $migration, 'rows' => [], 'incidents' => []];

    foreach ((new TeachersStep)->batches($migration) as $batch) {
        $out['rows'] = array_values([...$batch->rows]);
        $out['incidents'] = $batch->incidents;
    }

    return $out;
}

/**
 * @param  list<array<string, scalar|null>>  $rows
 * @return array<string, scalar|null>|null
 */
function teacherRowFor(array $rows, string $rfc): ?array
{
    return collect($rows)->firstWhere('rfc', $rfc);
}

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

it('migra solo docentes y no administrativos', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101AB1'),
        teacherStaffRow('ADMI800101AA1', ['nombramiento' => 'A']),
        teacherStaffRow('ADMI800202BB2', ['nombramiento' => 'A']),
    ], [teacherStaff2Row('SOLO800303CC3')]);
    SiiFixtures::writeGroups($this->dir, [['20263', 'X', 'A', 'ADMI800202BB2'], ['20263', 'X', 'B', 'SOLO800303CC3']]);

    $run = teachersStepRun($this->dir);
    $warnings = collect($run['incidents'])->pluck('message')->implode("\n");

    expect(array_column($run['rows'], 'rfc'))->toBe(['ADMI800202BB2', 'PRUE800101AB1', 'SOLO800303CC3'])
        ->and($warnings)->toContain('1 personas del SII no son docentes')
        ->and($warnings)->not->toContain('ADMI800101AA1')
        ->and(array_keys($run['migration']->remember('teacher_for_rfc', fn () => [])))->not->toContain('ADMI800101AA1');
});

it('toma el nombre de docentes2 si está y si no de docentes, en tipo título', function () {
    SiiFixtures::writeStaff($this->dir, [teacherStaffRow('PRUE800101AB1'), teacherStaffRow('PRUE800202AB2', ['nombre_empleado' => 'ANA MARIA', 'apellidos_empleado' => 'DEL RIO'])], [teacherStaff2Row('PRUE800101AB1')]);
    SiiFixtures::writeGroups($this->dir, []);

    $rows = teachersStepRun($this->dir)['rows'];

    expect(teacherRowFor($rows, 'PRUE800101AB1')['name'])->toBe('Maria Gomez Luna')
        ->and(teacherRowFor($rows, 'PRUE800202AB2')['name'])->toBe('Ana Maria del Rio');
});

it('activa a quien tiene grupo en el periodo vigente aunque no esté en Docentes2', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101AB1'),
        teacherStaffRow('PRUE800202AB2'),
        teacherStaffRow('PRUE800303AB3'),
        teacherStaffRow('PRUE800404AB4', ['nombramiento' => 'A']),
    ], [teacherStaff2Row('PRUE800202AB2')]);
    SiiFixtures::writeGroups($this->dir, [
        ['20263', 'X', 'A', 'PRUE800101AB1'], ['20261', 'X', 'A', 'PRUE800303AB3'], ['20263', 'X', 'A', 'PRUE800404AB4'],
    ]);

    $rows = teachersStepRun($this->dir)['rows'];

    expect(teacherRowFor($rows, 'PRUE800101AB1')['active'])->toBe('Sí')
        ->and(teacherRowFor($rows, 'PRUE800202AB2')['active'])->toBe('Sí')
        ->and(teacherRowFor($rows, 'PRUE800303AB3')['active'])->toBe('No')
        ->and(teacherRowFor($rows, 'PRUE800404AB4')['active'])->toBe('Sí');
});

it('el historial del RFC viejo pasa al docente del RFC nuevo', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101OLD', ['status_empleado' => '04']),
        teacherStaffRow('PRUE800101NEW'),
        teacherStaffRow('SOLO800202OLD', ['status_empleado' => '04']),
    ]);
    SiiFixtures::writeGroups($this->dir, [['20251', 'X', 'A', 'PRUE800101OLD'], ['20253', 'X', 'B', 'PRUE800101NEW'], ['20251', 'X', 'C', 'SOLO800202OLD']]);

    $run = teachersStepRun($this->dir);
    $teacherFor = $run['migration']->remember('teacher_for_rfc', fn () => []);

    expect($teacherFor['PRUE800101OLD'])->toBe('PRUE800101NEW')
        ->and($teacherFor['PRUE800101NEW'])->toBe('PRUE800101NEW')
        ->and(array_column($run['rows'], 'rfc'))->toBe(['PRUE800101NEW', 'SOLO800202OLD'])
        ->and(teacherRowFor($run['rows'], 'SOLO800202OLD')['active'])->toBe('No')
        ->and(collect($run['incidents'])->where('ref', 'docentes.csv:4')->pluck('message')->implode(''))->toContain('estatus 04')
        ->and(json_encode($run['incidents']))->not->toContain('SOLO800202OLD')->not->toContain('PRUE800101OLD');
});

it('un 04 con varios candidatos no se funde', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101OLD', ['status_empleado' => '04']),
        teacherStaffRow('PRUE800101NE1'),
        teacherStaffRow('PRUE800101NE2'),
    ]);
    SiiFixtures::writeGroups($this->dir, []);

    $run = teachersStepRun($this->dir);

    expect(array_column($run['rows'], 'rfc'))->toBe(['PRUE800101NE1', 'PRUE800101NE2', 'PRUE800101OLD'])
        ->and(collect($run['incidents'])->where('ref', 'docentes.csv:2')->pluck('message')->implode(''))->toContain('varios')
        ->and(json_encode($run['incidents']))->not->toContain('PRUE800101');
});

it('convierte la quincena de ingreso a fecha', function (string $plantel, string $date) {
    SiiFixtures::writeStaff($this->dir, [teacherStaffRow('PRUE800101AB1', ['inicio_plantel' => $plantel])]);
    SiiFixtures::writeGroups($this->dir, []);

    expect(teachersStepRun($this->dir)['rows'][0]['hired_on'])->toBe($date);
})->with([
    'quincena impar' => ['201701', '2017-01-01'],
    'quincena par' => ['201702', '2017-01-16'],
    'última' => ['201524', '2015-12-16'],
    'comodín 1900' => ['190001', ''],
    'quincena fuera de rango' => ['201725', ''],
    'comodín 676767' => ['676767', ''],
    'año futuro' => ['299901', ''],
    'vacío' => ['', ''],
]);

it('prefiere la fecha de ingreso de docentes2 y descarta la inválida', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101AB1', ['inicio_plantel' => '201701']),
        teacherStaffRow('PRUE800202AB2', ['inicio_plantel' => '201701']),
    ], [
        teacherStaff2Row('PRUE800101AB1', ['fecha_ingreso_institucion' => '2015-08-16 00:00:00']),
        teacherStaff2Row('PRUE800202AB2', ['fecha_ingreso_institucion' => '1900-01-01']),
    ]);
    SiiFixtures::writeGroups($this->dir, []);

    $rows = teachersStepRun($this->dir)['rows'];

    expect(teacherRowFor($rows, 'PRUE800101AB1')['hired_on'])->toBe('2015-08-16')
        ->and(teacherRowFor($rows, 'PRUE800202AB2')['hired_on'])->toBe('2017-01-01');
});

it('un docente sin departamento académico toma el de sus grupos', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101AB1', ['clave_area' => '999999']),
        teacherStaffRow('PRUE800202AB2', ['clave_area' => '999999']),
    ]);
    SiiFixtures::write($this->dir.'/materias.csv', SiiFile::Materias, array_map(
        fn (array $m): string => SiiFixtures::line(SiiFile::Materias, ['materia' => "'{$m[0]}'", 'clave_area' => "'{$m[1]}'"]),
        [['M1', '110100'], ['M2', '110600'], ['M3', '999999']],
    ));
    SiiFixtures::writeGroups($this->dir, [
        ['20261', 'M1', 'A', 'PRUE800101AB1'], ['20263', 'M2', 'A', 'PRUE800101AB1'], ['20263', 'M2', 'B', 'PRUE800101AB1'], ['20263', 'M3', 'A', 'PRUE800202AB2'],
    ]);

    $run = teachersStepRun($this->dir);
    $messages = collect($run['incidents'])->pluck('message')->implode("\n");

    expect(array_column($run['rows'], 'rfc'))->toBe(['PRUE800101AB1'])
        ->and($run['rows'][0]['department'])->toBe('110600')
        ->and(collect($run['incidents'])->where('ref', 'docentes.csv:3')->pluck('message')->implode(''))->toContain('no tiene departamento académico')
        ->and($messages)->not->toContain('PRUE800202AB2')->toContain('1 docentes no se migran');
});

it('las incidencias de un docente lo señalan por archivo y línea, sin RFC ni nombre', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101AB1'),
        teacherStaffRow('SINN800101AB1', ['nombre_empleado' => '', 'apellidos_empleado' => '']),
    ], [
        teacherStaff2Row('PRUE800101AB1'),
        teacherStaff2Row('DOS2800101AB1', ['id_unidad_organica' => '999999', 'nombre_empleado' => 'ROSA', 'apellidop' => 'VEGA']),
    ]);
    SiiFixtures::writeGroups($this->dir, [['20263', 'M9', 'A', 'DOS2800101AB1']]);

    $incidents = collect(teachersStepRun($this->dir)['incidents']);

    expect($incidents->where('ref', 'docentes.csv:3')->pluck('message')->implode(''))->toContain('no trae nombre')
        ->and($incidents->where('ref', 'docentes2.csv:3')->pluck('message')->implode(''))->toContain('no tiene departamento académico')
        ->and($incidents->toJson())->not->toContain('SINN800101AB1')->not->toContain('DOS2800101AB1')->not->toContain('Rosa')->not->toContain('VEGA');
});

it('un docente antiguo toma el departamento de sus últimos grupos', function () {
    SiiFixtures::writeStaff($this->dir, [teacherStaffRow('PRUE800101AB1', ['clave_area' => '999999'])]);
    SiiFixtures::write($this->dir.'/materias.csv', SiiFile::Materias, array_map(
        fn (array $m): string => SiiFixtures::line(SiiFile::Materias, ['materia' => "'{$m[0]}'", 'clave_area' => "'{$m[1]}'"]),
        [['M1', '110100'], ['M2', '110600']],
    ));
    SiiFixtures::writeGroups($this->dir, [['20171', 'M1', 'A', 'PRUE800101AB1'], ['20173', 'M1', 'A', 'PRUE800101AB1'], ['20173', 'M2', 'A', 'PRUE800101AB1'], ['20263', 'M2', 'Z', 'OTRO800101AB1']]);

    expect(teachersStepRun($this->dir)['rows'][0]['department'])->toBe('110100');
});

it('si docentes2 trae el nombre en blanco usa el de docentes', function () {
    SiiFixtures::writeStaff($this->dir, [teacherStaffRow('PRUE800101AB1')], [teacherStaff2Row('PRUE800101AB1', ['nombre_empleado' => '', 'apellidop' => '', 'apellidom' => ''])]);
    SiiFixtures::writeGroups($this->dir, []);

    expect(teachersStepRun($this->dir)['rows'][0]['name'])->toBe('Juan Perez de la Cruz');
});

it('la adscripción de docentes2 manda sobre el área de docentes', function () {
    SiiFixtures::writeStaff($this->dir, [teacherStaffRow('PRUE800101AB1', ['clave_area' => '110100'])], [teacherStaff2Row('PRUE800101AB1', ['id_unidad_organica' => '110600'])]);
    SiiFixtures::writeGroups($this->dir, []);

    expect(teachersStepRun($this->dir)['rows'][0]['department'])->toBe('110600');
});

it('deja el correo inválido vacío y lo cuenta una vez', function () {
    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101AB1', ['correo_electronico' => 'Ana@itcj.edu.mx']),
        teacherStaffRow('PRUE800202AB2', ['correo_electronico' => 'sin arroba']),
        teacherStaffRow('PRUE800303AB3', ['correo_electronico' => 'otro invalido']),
    ]);
    SiiFixtures::writeGroups($this->dir, []);

    $run = teachersStepRun($this->dir);

    expect(teacherRowFor($run['rows'], 'PRUE800101AB1')['email'])->toBe('ana@itcj.edu.mx')
        ->and(teacherRowFor($run['rows'], 'PRUE800202AB2')['email'])->toBe('')
        ->and(collect($run['incidents'])->filter(fn (array $i): bool => str_contains($i['message'], '2 correos')))->toHaveCount(1);
});

it('deja vacío el grado y lo reporta una vez', function () {
    SiiFixtures::writeStaff($this->dir, [teacherStaffRow('PRUE800101AB1'), teacherStaffRow('PRUE800202AB2')], [teacherStaff2Row('PRUE800101AB1')]);
    SiiFixtures::writeGroups($this->dir, []);

    $run = teachersStepRun($this->dir);
    $degree = collect($run['incidents'])->filter(fn (array $i): bool => str_contains($i['message'], 'el grado viene en letras sin catálogo'));

    expect(array_unique(array_column($run['rows'], 'degree')))->toBe([''])
        ->and($degree)->toHaveCount(1);
});

it('normaliza los RFC con espacios y minúsculas', function () {
    SiiFixtures::writeStaff($this->dir, [teacherStaffRow('prue 800101ab1')]);
    SiiFixtures::writeGroups($this->dir, [['20263', 'X', 'A', 'PRUE800101AB1 ']]);

    $run = teachersStepRun($this->dir);

    expect(array_column($run['rows'], 'rfc'))->toBe(['PRUE800101AB1'])
        ->and($run['rows'][0]['active'])->toBe('Sí');
});

it('sin el mapa de departamentos pide correr antes el organigrama', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');

    expect(fn () => [...(new TeachersStep)->batches($migration)])->toThrow(SiiStepOutOfOrder::class, 'department_for_area');
});

it('migra docentes e historial de punta a punta sin filas con error', function () {
    Storage::fake('imports');
    Campus::factory()->create(['code' => 'C1']);
    User::factory()->admin()->create(['username' => 'migrador']);

    $curated = sys_get_temp_dir().'/sii-curated-'.uniqid();
    File::makeDirectory($curated, 0775, true);
    File::copy(base_path('app/Modules/Imports/Sii/Curated/organigrama.csv'), $curated.'/organigrama.csv');
    File::put($curated.'/carreras.csv', "carrera,nombre,departamento,departamento_nombre,nota\nAAA,Carrera Triple A,110700,Económico Administrativas,\n");
    File::put($curated.'/planes.csv', "sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota\nA,1,AAA-2010-224,AAA,presencial,si,0,\n");

    $areas = array_column((new SiiMigration(SiiExport::open($this->dir), $curated))->curated('organigrama'), 'clave_sii');
    File::put($this->dir.'/organigrama.csv', "clave_area,descripcion_area,area_depende,nivel,tipo_area,p_sustantivos,pro_sus,p_sus,p_s\n"
        .implode("\n", array_map(fn (string $area): string => "{$area},AREA {$area},100000,1,X,,,,", $areas))."\n");
    SiiFixtures::write($this->dir.'/carreras.csv', SiiFile::Carreras, [SiiFixtures::line(SiiFile::Carreras, [
        'carrera' => "'A'", 'reticula' => "'1'", 'fecha_inicio' => "'15/08/2010'",
    ])]);
    SiiFixtures::write($this->dir.'/alumnos_todos.csv', SiiFile::Alumnos, []);

    $subject = SiiFile::Materias;
    SiiFixtures::write($this->dir.'/materias.csv', $subject, [
        SiiFixtures::line($subject, ['materia' => "'CD1'", 'tipo_materia' => '1', 'clave_area' => "'110100'", 'nombre_completo_materia' => "'CALCULO DIFERENCIAL'"]),
        SiiFixtures::line($subject, ['materia' => "'ZZZ'", 'tipo_materia' => '1', 'clave_area' => "'110100'", 'nombre_completo_materia' => "'SIN LUGAR'"]),
    ]);
    $placements = SiiFile::MateriasCarreras;
    SiiFixtures::write($this->dir.'/materias_carreras.csv', $placements, [SiiFixtures::line($placements, [
        'carrera' => "'A'", 'reticula' => '1', 'materia' => "'CD1'", 'creditos_materia' => '5', 'horas_teoricas' => '3', 'horas_practicas' => '2',
        'semestre_reticula' => '1', 'especialidad' => "''", 'estatus_materia_carrera' => "'A'",
    ])]);
    SiiFixtures::write($this->dir.'/materias_claves_tecnm.csv', SiiFile::MateriasClavesTecnm, []);
    SiiFixtures::write($this->dir.'/especialidades.csv', SiiFile::Especialidades, []);

    SiiFixtures::writeStaff($this->dir, [
        teacherStaffRow('PRUE800101OLD', ['status_empleado' => '04']),
        teacherStaffRow('PRUE800101NEW', ['correo_electronico' => 'nuevo@itcj.edu.mx', 'inicio_plantel' => '201702']),
        teacherStaffRow('ADMI800101AA1', ['nombramiento' => 'A']),
    ], [teacherStaff2Row('PRUE800101NEW', ['fecha_ingreso_institucion' => '2015-08-16'])]);
    SiiFixtures::writeGroups($this->dir, [
        ['20251', 'CD1', 'A', 'PRUE800101OLD'], ['20263', 'CD1', 'B', 'PRUE800101NEW'], ['20263', 'ZZZ', 'C', 'PRUE800101NEW'], ['20263', 'CD1', 'D', ''], ['20263', 'CD1', 'B', 'PRUE800101NEW'], ['20263', 'ZZZ', 'E', 'PRUE800101NEW', 'CD1        B'],
    ]);

    app()->instance('sii.steps', [OrgChartStep::class, CareersStep::class, SubjectsStep::class, TeachersStep::class, TeacherHistoryStep::class]);
    app()->when(RunSiiMigration::class)->needs('$curatedDir')->give($curated);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $teacher = Teacher::query()->where('rfc', 'PRUE800101NEW')->sole();

    expect(Teacher::query()->count())->toBe(1)
        ->and($teacher->name)->toBe('Maria Gomez Luna')
        ->and($teacher->is_active)->toBeTrue()
        ->and($teacher->hired_on->toDateString())->toBe('2015-08-16')
        ->and($teacher->history()->count())->toBe(2)
        ->and(Department::query()->where('code', '110100')->exists())->toBeTrue()
        ->and(ImportBatch::query()->whereIn('template', ['teachers', 'teacher_history'])->sum('error_rows'))->toBe(0)
        ->and(file_get_contents($this->dir.'/_incidencias.csv'))->toContain('grado viene en letras');

    File::deleteDirectory($curated);
});
