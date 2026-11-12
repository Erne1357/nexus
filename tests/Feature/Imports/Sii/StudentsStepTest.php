<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiCsvReader;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\CareersStep;
use App\Modules\Imports\Sii\Steps\OrgChartStep;
use App\Modules\Imports\Sii\Steps\StudentsStep;
use App\Modules\Imports\Sii\TeacherRoster;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `alumnos` de imports:migrate-sii (D5, D11, D12, D13): todos los renglones, plan y modalidad por `plan_for`,
| estatus agrupados con el código del SII como motivo, aspirantes inactivos y solo las columnas que el paso necesita
| (nunca el NIP ni la CURP). Todo con datos sintéticos.
*/

const STUDENTS_CURATED_PLANS = <<<'CSV'
sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota
A,1,AAA-2010-224,AAA,presencial,si,0,
A2,2,AAA-2010-224,AAA,distancia,si,0,
X,9,XXX-2010-999,XXX,presencial,no,0,excluida
CSV;

const STUDENTS_CURATED_CAREERS = <<<'CSV'
carrera,nombre,departamento,departamento_nombre,nota
AAA,Carrera Triple A,110700,Económico Administrativas,
CSV;

/**
 * @param  list<array<string, string>>  $students  texto sin comillas por columna (`semestre` va como número)
 */
function writeSiiStudents(string $dir, array $students): void
{
    SiiFixtures::write($dir.'/alumnos_todos.csv', SiiFile::Alumnos, array_map(function (array $s): string {
        $defaults = ['carrera' => 'A', 'reticula' => '1', 'estatus_alumno' => 'ACT', 'campus' => '1', 'semestre' => '3', 'nombre_alumno' => 'ANA', 'apellido_paterno' => 'LOPEZ', 'apellido_materno' => 'RUIZ'];
        $cells = [];
        foreach ([...$defaults, ...$s] as $column => $value) {
            $cells[$column] = $column === 'semestre' ? $value : "'{$value}'";
        }

        return SiiFixtures::line(SiiFile::Alumnos, $cells);
    }, $students));
}

/**
 * @return array{rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}
 */
function studentsStepRun(string $dir): array
{
    $migration = new SiiMigration(SiiExport::open($dir), '/nonexistent');
    $migration->remember('plan_for', fn (): array => [
        'A/1' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'presencial'],
        'A2/2' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'distancia'],
    ]);
    $out = ['rows' => [], 'incidents' => []];

    foreach ((new StudentsStep)->batches($migration) as $batch) {
        expect($batch->template)->toBe('students');
        $out = ['rows' => array_values([...$batch->rows]), 'incidents' => $batch->incidents];
    }

    return $out;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sii-'.bin2hex(random_bytes(6));
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('agrupa los 21 estatus del SII y guarda el motivo', function () {
    $codes = ['ACT', 'INS', 'BT1', 'BT2', 'BT3', 'BT4', 'BD1', 'BD2', 'BD3', 'BD4', 'BD5', 'BD6', 'BD7', 'BD8', 'BD9', 'BCP', 'BDF', 'TRA', 'EGR', 'TIT'];
    writeSiiStudents($this->dir, array_map(fn (string $code, int $i): array => ['no_de_control' => 'S'.$i, 'estatus_alumno' => $code], $codes, array_keys($codes)));

    $byReason = array_column(studentsStepRun($this->dir)['rows'], null, 'status_reason');

    expect($byReason)->toHaveCount(20);
    foreach (['ACT', 'INS'] as $code) {
        expect($byReason[$code]['status'])->toBe('activo');
    }
    foreach (['BT1', 'BT2', 'BT3', 'BT4'] as $code) {
        expect($byReason[$code]['status'])->toBe('baja_temporal');
    }
    foreach (['BD1', 'BD2', 'BD3', 'BD4', 'BD5', 'BD6', 'BD7', 'BD8', 'BD9', 'BCP', 'BDF', 'TRA'] as $code) {
        expect($byReason[$code]['status'])->toBe('baja_definitiva');
    }
    foreach (['EGR', 'TIT'] as $code) {
        expect($byReason[$code]['status'])->toBe('egresado');
    }
});

it('marca activos a los inscritos y a las bajas temporales', function () {
    writeSiiStudents($this->dir, [
        ['no_de_control' => 'S1', 'estatus_alumno' => 'ACT'], ['no_de_control' => 'S2', 'estatus_alumno' => 'INS'],
        ['no_de_control' => 'S3', 'estatus_alumno' => 'BT2'], ['no_de_control' => 'S4', 'estatus_alumno' => 'BD1'],
        ['no_de_control' => 'S5', 'estatus_alumno' => 'EGR'],
    ]);

    expect(array_column(studentsStepRun($this->dir)['rows'], 'active', 'control_number'))
        ->toBe(['S1' => 'Sí', 'S2' => 'Sí', 'S3' => 'Sí', 'S4' => 'No', 'S5' => 'No']);
});

it('migra a los aspirantes inactivos', function () {
    writeSiiStudents($this->dir, [['no_de_control' => 'S1', 'estatus_alumno' => 'ASP']]);

    $row = studentsStepRun($this->dir)['rows'][0];

    expect($row)->toMatchArray(['status' => '', 'active' => 'No', 'status_reason' => 'ASP']);
});

it('deja sin estatus un código desconocido con un aviso por código', function () {
    writeSiiStudents($this->dir, [
        ['no_de_control' => 'S1', 'estatus_alumno' => 'ZZZ'], ['no_de_control' => 'S2', 'estatus_alumno' => 'ZZZ'],
    ]);

    $run = studentsStepRun($this->dir);

    expect(array_column($run['rows'], 'status'))->toBe(['', ''])
        ->and($run['incidents'])->toHaveCount(1)
        ->and($run['incidents'][0]['message'])->toContain('ZZZ')->toContain('2')
        ->and($run['incidents'][0]['message'])->not->toContain('S1');
});

it('toma la modalidad de la variante del SII y mapea el campus', function () {
    writeSiiStudents($this->dir, [
        ['no_de_control' => 'S1', 'carrera' => 'A', 'reticula' => '1', 'campus' => '1'],
        ['no_de_control' => 'S2', 'carrera' => 'A2', 'reticula' => '2', 'campus' => '2'],
        ['no_de_control' => 'S3', 'campus' => '7'],
    ]);

    $run = studentsStepRun($this->dir);

    expect($run['rows'])->toHaveCount(2)
        ->and($run['rows'][0])->toMatchArray(['career' => 'AAA', 'study_plan' => 'AAA-2010-224', 'modality' => 'presencial', 'campus' => 'C1'])
        ->and($run['rows'][1])->toMatchArray(['modality' => 'distancia', 'campus' => 'C2'])
        ->and($run['incidents'])->toHaveCount(1)
        ->and($run['incidents'][0]['message'])->toContain('7');
});

it('descarta un semestre basura con advertencia', function () {
    writeSiiStudents($this->dir, [
        ['no_de_control' => 'S1', 'semestre' => '4'], ['no_de_control' => 'S2', 'semestre' => '99'], ['no_de_control' => 'S3', 'semestre' => '0'],
    ]);

    $run = studentsStepRun($this->dir);

    expect(array_column($run['rows'], 'semester'))->toBe(['4', '', ''])
        ->and($run['incidents'])->toHaveCount(1)
        ->and($run['incidents'][0]['message'])->toContain('2');
});

it('escribe el nombre en tipo título y no emite al que no trae nombre', function () {
    writeSiiStudents($this->dir, [
        ['no_de_control' => 'S1', 'nombre_alumno' => 'MARIA JOSE', 'apellido_paterno' => 'DE LA CRUZ', 'apellido_materno' => ''],
        ['no_de_control' => 'S2', 'nombre_alumno' => '', 'apellido_paterno' => '', 'apellido_materno' => ''],
    ]);

    $run = studentsStepRun($this->dir);

    expect($run['rows'])->toHaveCount(1)
        ->and($run['rows'][0]['name'])->toBe('Maria Jose de la Cruz')
        ->and($run['incidents'])->toHaveCount(1);
});

it('un alumno de una retícula excluida se reporta sin datos personales', function () {
    writeSiiStudents($this->dir, [
        ['no_de_control' => 'S1'],
        ['no_de_control' => 'S2', 'carrera' => 'X', 'reticula' => '9', 'nombre_alumno' => 'PERSONA SECRETA'],
        ['no_de_control' => 'S3', 'carrera' => 'X', 'reticula' => '9'],
    ]);

    $run = studentsStepRun($this->dir);
    $message = $run['incidents'][0]['message'];

    expect($run['rows'])->toHaveCount(1)
        ->and($run['incidents'])->toHaveCount(1)
        ->and($message)->toContain('X/9')->toContain('2')
        ->and($message)->not->toContain('S2')->not->toContain('SECRETA');
});

it('solo lee las columnas que usa', function () {
    expect(StudentsStep::COLUMNS)->toEqualCanonicalizing([
        'no_de_control', 'carrera', 'reticula', 'semestre', 'estatus_alumno', 'apellido_paterno', 'apellido_materno', 'nombre_alumno', 'campus',
    ]);
});

it('nunca lee el NIP ni la CURP', function () {
    writeSiiStudents($this->dir, [['no_de_control' => 'S1', 'nip' => 'secreto', 'curp_alumno' => 'CURPSECRETA']]);

    $columns = StudentsStep::COLUMNS;
    $rows = iterator_to_array((new SiiCsvReader($this->dir.'/alumnos_todos.csv', SiiFile::Alumnos))->rows($columns));

    expect(array_keys(reset($rows)))->toBe($columns)
        ->and($columns)->not->toContain('nip')->not->toContain('curp_alumno')->not->toContain('fecha_nacimiento')
        ->and(json_encode(studentsStepRun($this->dir)))->not->toContain('secreto')->not->toContain('CURPSECRETA');
});

it('migra a los alumnos de punta a punta sin errores', function () {
    Storage::fake('imports');
    $curated = sys_get_temp_dir().'/sii-curated-'.bin2hex(random_bytes(6));
    File::makeDirectory($curated, 0775, true);
    File::put($curated.'/planes.csv', STUDENTS_CURATED_PLANS);
    File::put($curated.'/carreras.csv', STUDENTS_CURATED_CAREERS);
    File::copy(base_path('app/Modules/Imports/Sii/Curated/organigrama.csv'), $curated.'/organigrama.csv');
    $areas = array_column((new SiiMigration(SiiExport::open($this->dir), $curated))->curated('organigrama'), 'clave_sii');
    File::put($this->dir.'/organigrama.csv', "clave_area,descripcion_area,area_depende,nivel,tipo_area,p_sustantivos,pro_sus,p_sus,p_s\n"
        .implode("\n", array_map(fn (string $area): string => "{$area},AREA {$area},100000,1,X,,,,", $areas))."\n");
    Campus::factory()->create(['code' => 'C1']);
    Campus::factory()->create(['code' => 'C2']);
    User::factory()->admin()->create(['username' => 'migrador']);

    $careers = SiiFile::Carreras;
    SiiFixtures::write($this->dir.'/carreras.csv', $careers, array_map(fn (array $c): string => SiiFixtures::line($careers, [
        'carrera' => "'{$c[0]}'", 'reticula' => "'{$c[1]}'", 'fecha_inicio' => "'15/08/2010'",
    ]), [['A', '1'], ['A2', '2'], ['X', '9']]));
    writeSiiStudents($this->dir, [
        ['no_de_control' => 'S1', 'estatus_alumno' => 'ACT', 'periodo_ingreso_it' => '20253'],
        ['no_de_control' => 'S2', 'carrera' => 'A2', 'reticula' => '2', 'estatus_alumno' => 'ASP', 'campus' => '2'],
        ['no_de_control' => 'S3', 'estatus_alumno' => 'BD3'],
        ['no_de_control' => 'S4', 'carrera' => 'X', 'reticula' => '9'],
    ]);

    app()->instance('sii.steps', [OrgChartStep::class, CareersStep::class, StudentsStep::class]);
    app()->when(RunSiiMigration::class)->needs('$curatedDir')->give($curated);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $student = Student::query()->where('control_number', 'S2')->sole();

    expect(Student::query()->count())->toBe(3)
        ->and($student->status)->toBeNull()
        ->and($student->is_active)->toBeFalse()
        ->and($student->status_reason)->toBe('ASP')
        ->and($student->modality->code)->toBe('EAD')
        ->and(Student::query()->where('control_number', 'S1')->sole())->status->toBe(StudentStatus::Activo)->is_active->toBeTrue()
        ->and(Student::query()->where('control_number', 'S3')->sole())->is_active->toBeFalse()
        ->and(ImportBatch::query()->where('template', 'students')->sum('error_rows'))->toBe(0)
        ->and(StudyPlan::query()->count())->toBe(1)
        ->and(Career::query()->count())->toBe(1);

    File::deleteDirectory($curated);
});

it('los pasos de carreras y docentes tampoco leen el NIP, la CURP ni datos personales', function () {
    expect(CareersStep::STUDENT_COLUMNS)->not->toContain('nip')->not->toContain('curp_alumno')->not->toContain('fecha_nacimiento')
        ->and(TeacherRoster::STAFF_COLUMNS)->not->toContain('curp_empleado')->not->toContain('domicilio_empleado')->not->toContain('telefono_empleado');

    writeSiiStudents($this->dir, [['no_de_control' => 'S1', 'nip' => 'secreto', 'curp_alumno' => 'CURPSECRETA']]);
    $rows = iterator_to_array((new SiiCsvReader($this->dir.'/alumnos_todos.csv', SiiFile::Alumnos))->rows(CareersStep::STUDENT_COLUMNS));

    expect(array_keys(reset($rows)))->toBe(CareersStep::STUDENT_COLUMNS);
});
