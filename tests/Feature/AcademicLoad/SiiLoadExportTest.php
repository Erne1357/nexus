<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory as Snap;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Exports\Sii\SiiLoadExport;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Imports\Sii\SiiCsvReader;
use App\Modules\Imports\Sii\SiiFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
| Exporte al SII (tarea 4.6): `grupos.csv` y `horarios.csv` con la forma de las tablas del SII, a partir de las fotos
| publicadas.
*/

const GRUPOS_HEADER = 'periodo,materia,grupo,estatus_grupo,capacidad_grupo,alumnos_inscritos,folio_acta,paralelo_de,exclusivo_carrera,exclusivo_reticula,rfc,seleccionado_en_bloque,tipo_personal,alumnos_preinscritos';
const HORARIOS_HEADER = 'periodo,rfc,tipo_horario,dia_semana,hora_inicial,hora_final,materia,grupo,aula,actividad,consecutivo,vigencia_inicio,vigencia_fin,consecutivo_admvo';

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
});

/**
 * Publica una carga con estos grupos (o los de ejemplo) en el periodo y campus de la prueba.
 *
 * @param  list<array<string, mixed>>|null  $groups
 */
function publishLoad(?array $groups, array $load = [], array $snapshot = []): LoadSnapshot
{
    $loadModel = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create([
        'term_id' => test()->term->id, 'campus_id' => test()->campus->id, ...$load,
    ]);
    $factory = LoadSnapshot::factory()->state(['academic_load_id' => $loadModel->id, ...$snapshot]);

    return ($groups === null ? $factory : $factory->withGroups($groups))->create();
}

/** @return array{list<string>, list<array<string, string>>} */
function readBack(string $content, SiiFile $file): array
{
    $path = tempnam(sys_get_temp_dir(), 'sii');
    file_put_contents($path, $content);
    $reader = new SiiCsvReader($path, $file);
    $rows = array_values(iterator_to_array($reader->rows()));
    expect($reader->malformed())->toBe([])->and($reader->trailing())->toBe([]);
    unlink($path);

    return [$reader->header(), $rows];
}

function lines(string $content): array
{
    return explode("\n", rtrim(mb_convert_encoding($content, 'UTF-8', 'Windows-1252'), "\n"));
}

function exportFiles(): array
{
    return app(SiiLoadExport::class)->files(test()->term, test()->campus);
}

it('sin cargas publicadas devuelve los dos archivos solo con el encabezado', function () {
    AcademicLoad::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);

    $files = exportFiles();

    expect($files['grupos.csv'])->toBe(GRUPOS_HEADER."\n")
        ->and($files['horarios.csv'])->toBe(HORARIOS_HEADER."\n");
});

it('un grupo con dos sesiones da una fila de grupos y dos de horarios con relleno exacto', function () {
    publishLoad([Snap::group([
        'code' => 'A', 'capacity' => 30,
        'subject' => ['id' => Subject::factory()->create(['code' => '3KB'])->id, 'code' => '3KB', 'name' => 'X', 'credits' => 5, 'theory_hours' => 2, 'practice_hours' => 3, 'department_id' => (string) Str::uuid7()],
        'sessions' => [
            Snap::session(['day' => 3, 'starts_at' => '09:00', 'ends_at' => '10:00', 'room' => ['id' => (string) Str::uuid7(), 'code' => '308', 'name' => null, 'building' => ['id' => (string) Str::uuid7(), 'code' => 'A', 'name' => 'A'], 'owner_department_id' => null]]),
            Snap::session(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room' => ['id' => (string) Str::uuid7(), 'code' => 'G911', 'name' => null, 'building' => ['id' => (string) Str::uuid7(), 'code' => 'A', 'name' => 'A'], 'owner_department_id' => null]]),
        ],
    ])]);

    $files = exportFiles();

    expect(lines($files['grupos.csv']))->toBe([
        GRUPOS_HEADER,
        "'20271','3KB       ','A  ',,30,0,,,,,'OISM850312AB1',,,",
    ])->and(lines($files['horarios.csv']))->toBe([
        HORARIOS_HEADER,
        "'20271','OISM850312AB1','D',2,1900-01-01 07:00:00.0,1900-01-01 09:00:00.0,'3KB       ','A  ','G911  ',,,,,",
        "'20271','OISM850312AB1','D',4,1900-01-01 09:00:00.0,1900-01-01 10:00:00.0,'3KB       ','A  ','308   ',,,,,",
    ]);
});

it('el grupo compartido da padre y paralelos con alias por plan, paralelo_de y cupos por carrera', function () {
    $subject = Subject::factory()->create(['code' => 'ACA-0909']);
    $ownerPlan = StudyPlan::factory()->create();
    $planA = StudyPlan::factory()->create();
    $planB = StudyPlan::factory()->create();
    $planC = StudyPlan::factory()->create();
    SubjectAlias::create(['subject_id' => $subject->id, 'study_plan_id' => $ownerPlan->id, 'code' => '2LE', 'source' => 'sii']);
    SubjectAlias::create(['subject_id' => $subject->id, 'study_plan_id' => $planA->id, 'code' => '3GE', 'source' => 'sii']);
    SubjectAlias::create(['subject_id' => $subject->id, 'study_plan_id' => null, 'code' => 'GENERIC', 'source' => 'sii']);
    $career = fn () => ['id' => (string) Str::uuid7(), 'code' => 'X', 'name' => 'X'];

    publishLoad([Snap::group([
        'code' => 'C', 'capacity' => 40,
        'subject' => ['id' => $subject->id, 'code' => 'ACA-0909', 'name' => 'T', 'credits' => 4, 'theory_hours' => 0, 'practice_hours' => 4, 'department_id' => (string) Str::uuid7()],
        'placements' => [
            Snap::placement(['career' => $career(), 'study_plan' => ['id' => $ownerPlan->id, 'code' => 'A'], 'seats' => 20]),
            Snap::placement(['career' => $c = $career(), 'study_plan' => ['id' => $planA->id, 'code' => 'B'], 'seats' => 15, 'is_owner' => false]),
            Snap::placement(['career' => $c, 'study_plan' => ['id' => $planA->id, 'code' => 'B'], 'seats' => 15, 'is_owner' => false]),
            Snap::placement(['career' => $c, 'study_plan' => ['id' => $planB->id, 'code' => 'C'], 'seats' => 15, 'is_owner' => false]),
            Snap::placement(['career' => $career(), 'study_plan' => ['id' => $planC->id, 'code' => 'D'], 'seats' => 5, 'is_owner' => false]),
            Snap::placement(['career' => $career(), 'study_plan' => null, 'semester' => null, 'seats' => 5, 'is_owner' => false]),
        ],
    ])]);

    $grupos = lines(exportFiles()['grupos.csv']);

    expect($grupos)->toHaveCount(4)
        ->and($grupos[1])->toBe("'20271','2LE       ','C  ',,20,0,,,,,'OISM850312AB1',,,")
        ->and($grupos[2])->toBe("'20271','3GE       ','C  ',,15,0,,'2LE    C         ',,,'OISM850312AB1',,,")
        ->and($grupos[3])->toBe("'20271','GENERIC   ','C  ',,15,0,,'2LE    C         ',,,'OISM850312AB1',,,");
    // Los planes B y C usan el alias sin plan (GENERIC): se emite una sola vez y el plan sin retícula no genera paralelo.
});

it('sin alias usa el código de la materia en Nexus y los horarios salen para cada paralelo', function () {
    $subject = Subject::factory()->create(['code' => 'SCC-1010']);
    $other = StudyPlan::factory()->create();
    SubjectAlias::create(['subject_id' => $subject->id, 'study_plan_id' => $other->id, 'code' => 'ZZ9', 'source' => 'sii']);

    publishLoad([Snap::group([
        'code' => 'M',
        'subject' => ['id' => $subject->id, 'code' => 'SCC-1010', 'name' => 'G', 'credits' => 4, 'theory_hours' => 2, 'practice_hours' => 2, 'department_id' => (string) Str::uuid7()],
        'placements' => [
            Snap::placement(['seats' => 20]),
            Snap::placement(['study_plan' => ['id' => $other->id, 'code' => 'O'], 'seats' => 10, 'is_owner' => false]),
        ],
        'capacity' => 30,
    ])]);

    $files = exportFiles();
    $grupos = lines($files['grupos.csv']);
    $horarios = lines($files['horarios.csv']);

    expect($grupos[1])->toContain("'SCC-1010  '")->and($grupos[1])->toContain(',20,0,')
        ->and($grupos[2])->toContain("'ZZ9       '")->and($grupos[2])->toContain("'SCC-1010    M    '")
        ->and($horarios)->toHaveCount(3)
        ->and($horarios[1])->toContain("'SCC-1010  '")->and($horarios[2])->toContain("'ZZ9       '");
});

it('una sesión virtual sin salón sale con aula EAD y un grupo sin docente con rfc vacío', function () {
    publishLoad([Snap::group([
        'teacher' => null,
        'sessions' => [Snap::session(['kind' => 'virtual', 'kind_label' => 'Virtual', 'room' => null])],
    ])]);

    $files = exportFiles();

    expect(lines($files['horarios.csv'])[1])->toBe("'20271',,'D',2,1900-01-01 07:00:00.0,1900-01-01 09:00:00.0,'SCD-1027  ','A  ','EAD   ',,,,,")
        ->and(lines($files['grupos.csv'])[1])->toContain(',0,,,,,,,,');
});

it('solo usa la última versión de cada carga', function () {
    $snapshot = publishLoad([Snap::group(['code' => 'A'])], snapshot: ['created_at' => CarbonImmutable::parse('2027-01-10 10:00')]);
    LoadSnapshot::factory()->withGroups([Snap::group(['code' => 'B'])])->create([
        'academic_load_id' => $snapshot->academic_load_id, 'version' => 2, 'created_at' => CarbonImmutable::parse('2027-01-11 10:00'),
    ]);

    $grupos = lines(exportFiles()['grupos.csv']);

    expect($grupos)->toHaveCount(2)->and($grupos[1])->toContain("'B  '");
});

it('exporta la última versión publicada aunque la carga se haya reabierto, e ignora otro campus u otro periodo', function () {
    publishLoad([Snap::group(['code' => 'A'])]);
    $reopened = publishLoad([Snap::group(['code' => 'R'])]);
    $reopened->academicLoad->update(['status' => LoadStatus::Adjusting]);
    AcademicLoad::factory()->inStatus(LoadStatus::Closed)->create(['term_id' => $reopened->academicLoad->term_id, 'campus_id' => $reopened->academicLoad->campus_id]);
    publishLoad([Snap::group(['code' => 'K'])], ['campus_id' => Campus::factory()->create()->id]);
    publishLoad([Snap::group(['code' => 'T'])], ['term_id' => Term::factory()->create(['code' => '20272'])->id]);

    $codes = array_map(fn (string $l): string => explode(',', $l)[2], array_slice(lines(exportFiles()['grupos.csv']), 1));

    expect($codes)->toEqualCanonicalizing(["'A  '", "'R  '"]);
});

it('ordena por departamento, materia y grupo', function () {
    $second = Department::factory()->create(['code' => 'ZZZ']);
    $first = Department::factory()->create(['code' => 'AAA']);
    publishLoad([Snap::group(['code' => 'B'])], ['department_id' => $second->id]);
    publishLoad([Snap::group(['code' => 'Z']), Snap::group(['code' => 'A'])], ['department_id' => $first->id]);

    $codes = array_map(fn (string $l): string => explode(',', $l)[2], array_slice(lines(exportFiles()['grupos.csv']), 1));

    expect($codes)->toBe(["'A  '", "'Z  '", "'B  '"]);
});

it('los archivos se leen de vuelta con el lector del SII y los valores coinciden', function () {
    publishLoad(null);

    $files = exportFiles();
    [$gHeader, $grupos] = readBack($files['grupos.csv'], SiiFile::Grupos);
    [$hHeader, $horarios] = readBack($files['horarios.csv'], SiiFile::Horarios);

    expect($gHeader)->toBe(SiiFile::Grupos->headers())->and($hHeader)->toBe(SiiFile::Horarios->headers())
        ->and($grupos)->toHaveCount(3)->and($horarios)->toHaveCount(6);

    $first = $grupos[0];
    expect($first['periodo'])->toBe('20271')->and($first['materia'])->toBe('ACA-0909')->and($first['grupo'])->toBe('D')->and($first['capacidad_grupo'])->toBe('20')
        ->and($first['rfc'])->toBe('DERJ780921QK4')->and($first['paralelo_de'])->toBe('')->and($first['alumnos_inscritos'])->toBe('0');
    $session = $horarios[0];
    expect($session['tipo_horario'])->toBe('D')->and($session['dia_semana'])->toBe('3')->and($session['hora_inicial'])->toBe('1900-01-01 10:00:00.0')
        ->and($session['aula'])->toBe('A-101')->and($session['actividad'])->toBe('');
});

it('la salida es Latin-1 válido y conserva la ñ y los acentos del código de un salón', function () {
    publishLoad([Snap::group(['sessions' => [Snap::session(['room' => ['id' => (string) Str::uuid7(), 'code' => 'Año-é', 'name' => null, 'building' => ['id' => (string) Str::uuid7(), 'code' => 'A', 'name' => 'A'], 'owner_department_id' => null]])]])]);

    $horarios = exportFiles()['horarios.csv'];
    [, $rows] = readBack($horarios, SiiFile::Horarios);

    expect(mb_check_encoding($horarios, 'UTF-8'))->toBeFalse()
        ->and(str_contains($horarios, "A\xF1o-\xE9 "))->toBeTrue()
        ->and($rows[0]['aula'])->toBe('Año-é');
});
