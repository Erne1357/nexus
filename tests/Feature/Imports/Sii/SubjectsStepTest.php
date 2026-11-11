<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\Steps\CareersStep;
use App\Modules\Imports\Sii\Steps\OrgChartStep;
use App\Modules\Imports\Sii\Steps\SubjectsStep;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `materias` de imports:migrate-sii (D8, D15, D16, D18): las claves del SII se unen por la clave oficial del
| TecNM, cada plan fundido tiene un solo lugar por materia, el nombre se normaliza, el tipo y el departamento se
| derivan, y una materia sin semestre nunca recibe un semestre que no venga del SII: en una retícula viva va a «Por
| validar» con el semestre sugerido de su misma carrera, y en una histórica queda como en el SII. Todo con datos sintéticos:
| materias, retículas y claves inventadas, y los mapas de los pasos anteriores armados a mano.
*/

const SUBJECTS_STEP_PLAN_FOR = [
    'A/1' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'presencial'],
    'A2/2' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'distancia'],
    'A/3' => ['plan' => 'AAA-2004-100', 'career' => 'AAA', 'modality' => 'presencial'],
    'B/1' => ['plan' => 'BBB-2010-300', 'career' => 'BBB', 'modality' => 'presencial'],
    'C/1' => ['plan' => 'CCC-2009-201', 'career' => 'CCC', 'modality' => 'presencial'],
];

const SUBJECTS_STEP_VERSIONS = ['AAA-2010-224' => '2010', 'AAA-2004-100' => '2004', 'BBB-2010-300' => '2010', 'CCC-2009-201' => '2009'];

const SUBJECTS_STEP_CAREERS = <<<'CSV'
carrera,nombre,departamento,departamento_nombre,nota
AAA,Carrera Triple A,110700,Económico Administrativas,
BBB,Carrera Doble B,110600,Sistemas,
CCC,Carrera Ce,110500,Eléctrica,
CSV;

/** materia, tipo, área, nombre */
const SUBJECTS_STEP_CATALOG = [
    ['CD1', '1', '110100', 'CALCULO DIFERENCIAL'],
    ['CD1D', '1', '110100', 'CALCULO DIFERENCIAL'],
    ['CD2', '1', '110100', 'CALCULO DIFERENCIAL'],
    ['CD3', '1', '110100', 'CALCULO DIFERENCIAL'],
    ['ETI', '1', '110100', 'TALLER DE ETICA'],
    ['ETB', '1', '110100', 'TALLER DE ETICA'],
    ['ETC', '1', '110100', 'TALLER DE ETICA'],
    ['OLD', '1', '110100', 'TALLER  DE ETICA'],
    ['PRB1', '1', '110100', 'PROBABILIDAD Y ESTADISTICA'],
    ['PRB2', '1', '110100', 'PROBABILIDAD Y ESTADÍSTICA'],
    ['OLDP', '1', '110100', 'PROBABILIDAD Y ESTADISTICA'],
    ['PRG', '3', '999999', 'PROGRAMACION WEB'],
    ['RES', '2', '110600', 'RESIDENCIA PROFESIONAL'],
    ['SS', '4', '110600', 'SERVICIO SOCIAL'],
    ['OPT', '2', '110600', 'TOPICOS DE ADMINISTRACION'],
    ['ESP', '3', '110600', 'MINERIA DE DATOS'],
    ['ESQ', '3', '110600', 'COMPUTO EN LA NUBE'],
    ['ZZZ', '1', '110100', 'MATERIA SIN PAR'],
    ['NNN', '1', '110100', 'MATERIA DADA DE BAJA'],
];

/** carrera, retícula, materia, créditos, teoría, práctica, semestre, especialidad, estatus */
const SUBJECTS_STEP_PLACEMENTS = [
    ['A2', '2', 'CD1D', '5', '3', '2', '2', '', 'A'],
    ['A', '1', 'CD1', '5', '3', '2', '1', '', 'A'],
    ['B', '1', 'CD2', '5', '3', '2', '1', '', 'A'],
    ['C', '1', 'CD3', '4', '2', '2', '1', '', 'A'],
    ['A', '1', 'ETI', '4', '0', '4', '1', '', 'A'],
    ['B', '1', 'ETB', '4', '0', '4', '2', '', 'A'],
    ['C', '1', 'ETC', '4', '0', '4', '2', '', 'A'],
    ['A', '3', 'OLD', '4', '0', '4', '0', '', 'A'],
    ['B', '1', 'PRB1', '5', '3', '2', '3', '', 'A'],
    ['C', '1', 'PRB2', '5', '3', '2', '4', '', 'A'],
    ['A', '3', 'OLDP', '5', '3', '2', '0', '', 'A'],
    ['A', '1', 'PRG', '5', '2', '3', '5', '', 'A'],
    ['A', '1', 'RES', '10', '0', '10', '9', '', 'A'],
    ['A', '1', 'SS', '10', '0', '10', '8', '', 'A'],
    ['A', '1', 'OPT', '4', '2', '2', '6', '', 'A'],
    ['A', '1', 'ESP', '5', '2', '3', '7', 'ESP-01', 'A'],
    ['A', '1', 'ESQ', '5', '2', '3', '7', 'ESP-99', 'A'],
    ['A', '3', 'ZZZ', '4', '2', '2', '0', '', 'A'],
    ['A', '1', 'NNN', '4', '2', '2', '3', '', 'N'],
    ['X', '9', 'XXM', '4', '2', '2', '1', '', 'A'],
    ['X', '9', 'XXN', '4', '2', '2', '2', '', 'A'],
];

/** carrera, retícula, materia, clave TecNM */
const SUBJECTS_STEP_TECNM = [
    ['A', '1', 'CD1', 'ACF-0901'],
    ['A2', '2', 'CD1D', 'ACF-0901'],
    ['B', '1', 'CD2', 'ACF-0901'],
    ['C', '1', 'CD3', 'ACF-0901'],
    ['A', '1', 'ETI', 'ACA-0907'],
    ['B', '1', 'ETB', 'ACA-0907'],
    ['C', '1', 'ETC', 'ACA-0907'],
    ['B', '1', 'PRB1', 'AEF-1052'],
    ['C', '1', 'PRB2', 'AEF-1052'],
    ['A', '1', 'PRG', 'AEB-1055'],
    ['A', '1', 'RES', 'RES-0001'],
];

/** especialidad, carrera, retícula, nombre */
const SUBJECTS_STEP_SPECIALTIES = [
    ['ESP-01', 'A', '1', 'INTELIGENCIA DE NEGOCIOS'],
];

/**
 * Escribe los cuatro archivos de materias del SII con las claves rellenas de espacios, como las exporta el SII.
 *
 * @param  list<array{string, string, string, string}>  $catalog
 * @param  list<array{string, string, string, string, string, string, string, string, string}|array{string, string, string, string, string, string, string, string, string, string}>  $placements
 * @param  list<array{string, string, string, string}>  $tecnm
 * @param  list<array{string, string, string, string}>  $specialties
 */
function writeSiiSubjects(string $dir, array $catalog, array $placements, array $tecnm = [], array $specialties = []): void
{
    $text = fn (string $value): string => "'".str_pad($value, 10)."'";

    SiiFixtures::write($dir.'/materias.csv', SiiFile::Materias, array_map(fn (array $m): string => SiiFixtures::line(SiiFile::Materias, [
        'materia' => $text($m[0]), 'tipo_materia' => $m[1], 'clave_area' => $text($m[2]), 'nombre_completo_materia' => "'{$m[3]}'",
    ]), $catalog));

    SiiFixtures::write($dir.'/materias_carreras.csv', SiiFile::MateriasCarreras, array_map(fn (array $p): string => SiiFixtures::line(SiiFile::MateriasCarreras, [
        'carrera' => $text($p[0]), 'reticula' => $p[1], 'materia' => $text($p[2]), 'creditos_materia' => $p[3], 'horas_teoricas' => $p[4],
        'horas_practicas' => $p[5], 'semestre_reticula' => $p[6], 'especialidad' => $text($p[7]), 'estatus_materia_carrera' => "'{$p[8]}'",
        'renglon' => $p[9] ?? '',
    ]), $placements));

    SiiFixtures::write($dir.'/materias_claves_tecnm.csv', SiiFile::MateriasClavesTecnm, array_map(fn (array $t): string => SiiFixtures::line(SiiFile::MateriasClavesTecnm, [
        'carrera' => $text($t[0]), 'reticula' => $t[1], 'materia' => $text($t[2]), 'clave_tecnm' => $text($t[3]),
    ]), $tecnm));

    SiiFixtures::write($dir.'/especialidades.csv', SiiFile::Especialidades, array_map(fn (array $s): string => SiiFixtures::line(SiiFile::Especialidades, [
        'especialidad' => $text($s[0]), 'carrera' => $text($s[1]), 'reticula' => $s[2], 'nombre_especialidad' => "'{$s[3]}'",
    ]), $specialties));
}

/**
 * Corre el paso con los mapas de los pasos anteriores armados a mano (`department_for_area` con llaves numéricas,
 * como lo deja el paso del organigrama).
 *
 * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
 * @param  array<string, true>  $historical  los planes de Nexus que son retículas históricas
 * @return array{migration: SiiMigration, subjects: array{rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}, subject_aliases: array{rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}}
 */
function subjectsStepRun(string $dir, string $curated, array $planFor = SUBJECTS_STEP_PLAN_FOR, array $historical = []): array
{
    $migration = new SiiMigration(SiiExport::open($dir), $curated);
    $migration->remember('plan_for', fn (): array => $planFor);
    $migration->remember('plan_version', fn (): array => SUBJECTS_STEP_VERSIONS);
    $migration->remember('historical_plans', fn (): array => $historical);
    $migration->remember('department_for_area', fn (): array => [110100 => '110100', 110600 => '110600', 999999 => null]);
    $out = ['migration' => $migration];

    foreach ((new SubjectsStep)->batches($migration) as $batch) {
        $out[$batch->template] = ['rows' => array_values([...$batch->rows]), 'incidents' => $batch->incidents];
    }

    return $out;
}

/**
 * @param  array{rows: list<array<string, scalar|null>>}  $batch
 * @return array<string, scalar|null>|null
 */
function subjectsStepRow(array $batch, string $plan, string $code): ?array
{
    return collect($batch['rows'])->first(fn (array $row): bool => $row['plan'] === $plan && $row['code'] === $code);
}

/**
 * @param  array{incidents: list<array{level: string, ref: string, message: string}>}  $batch
 */
function subjectsStepMessages(array $batch): string
{
    return collect($batch['incidents'])->map(fn (array $i): string => "{$i['level']}|{$i['ref']}|{$i['message']}")->implode("\n");
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sii-'.bin2hex(random_bytes(6));
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    $this->curated = sys_get_temp_dir().'/sii-curated-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->curated, 0775, true);
    File::put($this->curated.'/carreras.csv', SUBJECTS_STEP_CAREERS);

    writeSiiSubjects($this->dir, SUBJECTS_STEP_CATALOG, SUBJECTS_STEP_PLACEMENTS, SUBJECTS_STEP_TECNM, SUBJECTS_STEP_SPECIALTIES);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::deleteDirectory($this->curated);
});

it('une claves del SII por la clave TecNM', function () {
    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'ACF-0901'))->toMatchArray([
        'name' => 'Cálculo Diferencial', 'credits' => '5', 'theory_hours' => '3', 'practice_hours' => '2', 'department' => '110100', 'sii_code' => '',
    ])
        ->and(subjectsStepRow($subjects, 'BBB-2010-300', 'ACF-0901'))->not->toBeNull()
        ->and(collect($subjects['rows'])->pluck('code'))->not->toContain('CD1')->not->toContain('CD2')
        ->and(subjectsStepRow($subjects, 'BBB-2010-300', 'ACA-0907')['semester'])->toBe('2');
});

it('conserva la clave del SII cuando los créditos difieren y avisa', function () {
    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $warning = collect($subjects['incidents'])->first(fn (array $i): bool => $i['ref'] === 'CCC-2009-201/CD3');

    expect(subjectsStepRow($subjects, 'CCC-2009-201', 'CD3'))->toMatchArray(['credits' => '4', 'theory_hours' => '2'])
        ->and(subjectsStepRow($subjects, 'CCC-2009-201', 'ACF-0901'))->toBeNull()
        ->and($warning)->not->toBeNull()
        ->and($warning['level'])->toBe('warning')
        ->and($warning['message'])->toContain('ACF-0901')->toContain('CD3')->toContain('supuesto por confirmar');
});

it('en un empate de créditos gana el plan de versión más reciente y avisa', function () {
    writeSiiSubjects($this->dir, [['T1', '1', '110100', 'DIBUJO'], ['T2', '1', '110100', 'DIBUJO']], [
        ['C', '1', 'T2', '4', '2', '2', '1', '', 'A'],
        ['A', '1', 'T1', '5', '3', '2', '1', '', 'A'],
    ], [['C', '1', 'T2', 'TIE-0001'], ['A', '1', 'T1', 'TIE-0001']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'TIE-0001'))->toMatchArray(['credits' => '5'])
        ->and(subjectsStepRow($subjects, 'CCC-2009-201', 'T2'))->toMatchArray(['credits' => '4'])
        ->and(subjectsStepMessages($subjects))->toContain('TIE-0001')->toContain('empate')->toContain('AAA-2010-224');
});

it('asigna el departamento de la carrera a una materia sin área', function () {
    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $warnings = collect($subjects['incidents'])->filter(fn (array $i): bool => $i['ref'] === 'AEB-1055');

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'AEB-1055')['department'])->toBe('110700')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings->first()['message'])->toContain('AEB-1055')->toContain('110700')->toContain('AAA');
});

it('deja el alias del SII de cada lugar', function () {
    $aliases = collect(subjectsStepRun($this->dir, $this->curated)['subject_aliases']['rows'])->keyBy('code');

    expect($aliases['CD3'])->toBe(['code' => 'CD3', 'subject' => 'CD3', 'plan' => 'CCC-2009-201'])
        ->and($aliases['OLD'])->toBe(['code' => 'OLD', 'subject' => 'OLD', 'plan' => 'AAA-2004-100'])
        ->and($aliases['PRG'])->toBe(['code' => 'PRG', 'subject' => 'AEB-1055', 'plan' => 'AAA-2010-224'])
        ->and($aliases['CD2'])->toBe(['code' => 'CD2', 'subject' => 'ACF-0901', 'plan' => 'BBB-2010-300']);
});

it('deja todas las claves del SII como alias', function () {
    $run = subjectsStepRun($this->dir, $this->curated);
    $aliases = collect($run['subject_aliases']['rows'])->keyBy('code');
    $subjectFor = $run['migration']->remember('subject_for', fn () => throw new LogicException('debía estar armado'));

    expect($aliases['CD1']['subject'])->toBe('ACF-0901')
        ->and($aliases['CD1D'])->toBe(['code' => 'CD1D', 'subject' => 'ACF-0901', 'plan' => 'AAA-2010-224'])
        ->and($aliases->keys()->sort()->values()->all())->toBe(['CD1', 'CD1D', 'CD2', 'CD3', 'ESP', 'ESQ', 'ETB', 'ETC', 'ETI', 'NNN', 'OLD', 'OLDP', 'OPT', 'PRB1', 'PRB2', 'PRG', 'RES', 'SS', 'ZZZ'])
        ->and(collect($run['subjects']['rows'])->pluck('sii_code')->unique()->all())->toBe([''])
        ->and($subjectFor['CD1D'])->toBe('ACF-0901')
        ->and($subjectFor['CD3'])->toBe('CD3')
        ->and($subjectFor['ZZZ'])->toBe('ZZZ')
        ->and($subjectFor['NNN'])->toBe('NNN');
});

it('emite un solo lugar por plan aunque venga de dos variantes', function () {
    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $placements = collect($subjects['rows'])->where('plan', 'AAA-2010-224')->where('code', 'ACF-0901');
    $warning = collect($subjects['incidents'])->first(fn (array $i): bool => $i['ref'] === 'AAA-2010-224/ACF-0901');

    expect($placements)->toHaveCount(1)
        ->and($placements->first()['semester'])->toBe('1')
        ->and($warning)->not->toBeNull()
        ->and($warning['message'])->toContain('presencial');
});

it('normaliza el nombre de la materia', function () {
    $run = subjectsStepRun($this->dir, $this->curated);
    $subjects = $run['subjects'];

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'AEB-1055')['name'])->toBe('Programación Web')
        ->and(subjectsStepRow($subjects, 'AAA-2010-224', 'ACA-0907')['name'])->toBe('Taller de Ética')
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'OLD')['name'])->toBe('Taller de Ética')
        ->and(subjectsStepRow($subjects, 'AAA-2010-224', 'OPT')['name'])->toBe('Topicos de Administración');
});

it('toma el tipo de residencia por nombre', function () {
    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $kind = fn (string $code): ?string => subjectsStepRow($subjects, 'AAA-2010-224', $code)['kind'];

    expect($kind('RES-0001'))->toBe(SubjectKind::Residencia->value)
        ->and($kind('SS'))->toBe(SubjectKind::ServicioSocial->value)
        ->and($kind('ACF-0901'))->toBe(SubjectKind::Basica->value)
        ->and($kind('AEB-1055'))->toBe(SubjectKind::Especialidad->value)
        ->and($kind('OPT'))->toBe(SubjectKind::Complementaria->value);
});

it('trae la especialidad con su nombre; una que no está en el SII queda con su clave y avisa', function () {
    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'ESP'))->toMatchArray(['specialty' => 'ESP-01', 'specialty_name' => 'INTELIGENCIA DE NEGOCIOS'])
        ->and(subjectsStepRow($subjects, 'AAA-2010-224', 'ESQ'))->toMatchArray(['specialty' => 'ESP-99', 'specialty_name' => ''])
        ->and(subjectsStepRow($subjects, 'AAA-2010-224', 'ACF-0901')['specialty'])->toBe('')
        ->and(subjectsStepMessages($subjects))->toContain('ESP-99');
});

it('D18: en una retícula viva, una materia sin semestre va a «Por validar» con el semestre sugerido de su misma carrera, nunca de otra', function () {
    $run = subjectsStepRun($this->dir, $this->curated);
    $warning = fn (string $ref): string => collect($run['subjects']['incidents'])->first(fn (array $i): bool => $i['ref'] === $ref)['message'];

    expect(collect($run['subjects']['rows'])->where('plan', 'AAA-2004-100')->pluck('code')->all())->toBe([])
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'OLD')['suggested_semester'])->toBe('1')
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'OLDP')['suggested_semester'])->toBe('')
        ->and($warning('AAA-2004-100/OLD'))->toBe('Lugar AAA-2004-100/OLD sin semestre en el SII: no entra a la retícula; pasa a «Por validar», donde Servicios Escolares le asigna semestre, con el 1 sugerido por la materia con el mismo nombre de la misma carrera en AAA-2010-224/ACA-0907. Lo aprobado en ella cuenta (D18 y D19; confirmado, 10 de noviembre de 2026).')
        ->and($warning('AAA-2004-100/OLDP'))->toBe('Lugar AAA-2004-100/OLDP sin semestre en el SII: no entra a la retícula; pasa a «Por validar», donde Servicios Escolares le asigna semestre, sin sugerencia: ninguna materia con el mismo nombre de la misma carrera tiene semestre. Lo aprobado en ella cuenta (D18 y D19; confirmado, 10 de noviembre de 2026).');
});

it('D18: el semestre de «Circuitos Digitales» de un plan de otra carrera nunca llega a una retícula sin semestres, viva o histórica', function () {
    writeSiiSubjects($this->dir, [['CDA', '1', '110100', 'CIRCUITOS DIGITALES'], ['CDB', '1', '110100', 'CIRCUITOS DIGITALES'], ['QA', '1', '110100', 'QUIMICA']], [
        ['A', '3', 'CDA', '5', '3', '2', '0', '', 'A'],
        ['A', '3', 'QA', '4', '2', '2', '0', '', 'A'],
        ['B', '1', 'CDB', '5', '3', '2', '10', '', 'A'],
    ]);

    foreach ([[], ['AAA-2004-100' => true]] as $historical) {
        $run = subjectsStepRun($this->dir, $this->curated, historical: $historical);

        expect(subjectsStepRow($run['subjects'], 'AAA-2004-100', 'CDA'))->toBeNull()
            ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'CDA')['suggested_semester'])->toBe('')
            ->and(subjectsStepRow($run['subjects'], 'BBB-2010-300', 'CDB')['semester'])->toBe('10')
            ->and(subjectsStepMessages($run['subjects']))->not->toContain('el 10 sugerido');
    }
});

it('D18: una retícula histórica se migra como en el SII: conserva sus semestres y deja sin semestre, sin sugerencia, lo que el SII trae sin él o con estatus N, con un solo aviso', function () {
    writeSiiSubjects($this->dir, [...SUBJECTS_STEP_CATALOG, ['HID', '1', '110100', 'HIDRAULICA'], ['NNO', '1', '110100', 'MATERIA RETIRADA']], [
        ...SUBJECTS_STEP_PLACEMENTS,
        ['A', '3', 'HID', '4', '2', '2', '4', '', 'A'],
        ['A', '3', 'NNO', '4', '2', '2', '2', '', 'N'],
    ], SUBJECTS_STEP_TECNM, SUBJECTS_STEP_SPECIALTIES);

    $run = subjectsStepRun($this->dir, $this->curated, historical: ['AAA-2004-100' => true]);
    $pending = collect($run['pending_placements']['rows'])->where('plan', 'AAA-2004-100');
    $perPlace = collect($run['subjects']['incidents'])->filter(fn (array $i): bool => str_starts_with($i['ref'], 'AAA-2004-100/'))->pluck('message');
    $perPlan = collect($run['subjects']['incidents'])->filter(fn (array $i): bool => $i['ref'] === 'AAA-2004-100');

    expect(collect($run['subjects']['rows'])->where('plan', 'AAA-2004-100')->map(fn (array $row): string => "{$row['code']}:{$row['semester']}")->values()->all())->toBe(['HID:4'])
        ->and($pending->pluck('code')->sort()->values()->all())->toBe(['NNO', 'OLD', 'OLDP', 'ZZZ'])
        ->and($pending->pluck('suggested_semester')->unique()->values()->all())->toBe([''])
        ->and($perPlace->filter(fn (string $m): bool => str_contains($m, 'semestre') || str_contains($m, 'estatus N'))->all())->toBe([])
        ->and($perPlan->pluck('message')->all())->toBe(['Retícula histórica AAA-2004-100 (ningún alumno inscrito en el periodo actual): 3 materias quedan sin semestre, como en el SII, y 1 ya no se ofrece (estatus N); esperan en «Por validar» y Servicios Escolares solo les asigna semestre si vuelve un alumno (confirmado, 10 de noviembre de 2026).'])
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2010-224', 'NNN'))->not->toBeNull()
        ->and(subjectsStepMessages($run['subjects']))->toContain('warning|AAA-2010-224/NNN|');
});

it('empata el nombre de cualquiera de las claves del SII que se unieron en una materia', function () {
    writeSiiSubjects($this->dir, [
        ['K1', '1', '110100', 'FUNDAMENTOS DE INVESDTIGACION'],
        ['K2', '1', '110100', 'FUNDAMENTOS DE INVESTIGACION'],
        ['K3', '1', '110100', 'FUNDAMENTOS DE INVESTIGACION'],
        ['OLDF', '1', '110100', 'FUNDAMENTOS DE INVESDTIGACION'],
    ], [
        ['A', '1', 'K1', '4', '2', '2', '1', '', 'A'],
        ['A2', '2', 'K2', '4', '2', '2', '1', '', 'A'],
        ['B', '1', 'K3', '4', '2', '2', '2', '', 'A'],
        ['A', '3', 'OLDF', '4', '2', '2', '0', '', 'A'],
    ], [['A', '1', 'K1', 'ACC-0906'], ['A2', '2', 'K2', 'ACC-0906'], ['B', '1', 'K3', 'ACC-0906']]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $subjects = $run['subjects'];
    $warning = collect($subjects['incidents'])->first(fn (array $i): bool => $i['ref'] === 'ACC-0906');

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'ACC-0906')['name'])->toBe('Fundamentos de Investigación')
        ->and(subjectsStepRow($subjects, 'BBB-2010-300', 'ACC-0906')['name'])->toBe('Fundamentos de Investigación')
        ->and($warning['message'])->toContain('Fundamentos de Invesdtigacion')
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'OLDF')['suggested_semester'])->toBe('1');
});

it('en un empate de nombres toma el del plan más reciente', function () {
    writeSiiSubjects($this->dir, [['N1', '1', '110100', 'DIBUJO TECNICO'], ['N2', '1', '110100', 'DIBUJO INDUSTRIAL']], [
        ['C', '1', 'N2', '4', '2', '2', '1', '', 'A'],
        ['A', '1', 'N1', '4', '2', '2', '1', '', 'A'],
    ], [['C', '1', 'N2', 'DIB-0002'], ['A', '1', 'N1', 'DIB-0002']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'DIB-0002')['name'])->toBe('Dibujo Tecnico')
        ->and(subjectsStepRow($subjects, 'CCC-2009-201', 'DIB-0002')['name'])->toBe('Dibujo Tecnico');
});

it('funde la misma materia de dos variantes sin clave TecNM', function () {
    writeSiiSubjects($this->dir, [['VZ', '1', '110100', 'DIBUJO'], ['VA', '1', '110100', 'DIBUJO ']], [
        ['A2', '2', 'VA', '4', '2', '2', '3', '', 'A'],
        ['A', '1', 'VZ', '4', '2', '2', '2', '', 'A'],
    ]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $placements = collect($run['subjects']['rows'])->where('plan', 'AAA-2010-224');
    $subjectFor = $run['migration']->remember('subject_for', fn () => throw new LogicException('debía estar armado'));

    expect($placements->pluck('code')->all())->toBe(['VZ'])
        ->and($placements->first()['semester'])->toBe('2')
        ->and(collect($run['subject_aliases']['rows'])->keyBy('code')['VA'])->toBe(['code' => 'VA', 'subject' => 'VZ', 'plan' => 'AAA-2010-224'])
        ->and($subjectFor['VA'])->toBe('VZ')
        ->and(subjectsStepMessages($run['subjects']))->toContain('AAA-2010-224/VZ');

    $withoutPresencial = subjectsStepRun($this->dir, $this->curated, [...SUBJECTS_STEP_PLAN_FOR, 'A/1' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'mixta']]);

    expect(collect($withoutPresencial['subjects']['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->all())->toBe(['VA']);
});

it('funde la variante sin clave TecNM en la materia con clave TecNM', function () {
    writeSiiSubjects($this->dir, [['W1', '1', '110100', 'DIBUJO'], ['W2', '1', '110100', 'DIBUJO']], [
        ['A', '1', 'W1', '4', '2', '2', '1', '', 'A'],
        ['A2', '2', 'W2', '4', '2', '2', '1', '', 'A'],
    ], [['A2', '2', 'W2', 'DIB-0001']]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $aliases = collect($run['subject_aliases']['rows'])->keyBy('code');

    expect(collect($run['subjects']['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->all())->toBe(['DIB-0001'])
        ->and($aliases['W1']['subject'])->toBe('DIB-0001')
        ->and($aliases['W2']['subject'])->toBe('DIB-0001');
});

it('no funde materias con el mismo nombre dentro de una retícula y lo reporta', function () {
    writeSiiSubjects($this->dir, [['D1', '1', '110100', 'DIBUJO'], ['D2', '1', '110100', 'DIBUJO'], ['D3', '1', '110100', 'DIBUJO']], [
        ['A', '1', 'D2', '4', '2', '2', '1', '', 'A'],
        ['A', '1', 'D1', '4', '2', '2', '1', '', 'A'],
        ['A2', '2', 'D3', '4', '2', '2', '1', '', 'A'],
    ]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $warnings = collect($run['subjects']['incidents'])->filter(fn (array $i): bool => $i['ref'] === 'A/1');

    expect(collect($run['subjects']['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->sort()->values()->all())->toBe(['D1', 'D2'])
        ->and(collect($run['subject_aliases']['rows'])->keyBy('code')['D3']['subject'])->toBe('D1')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings->first()['message'])->toContain('«Dibujo» 2 veces')->toContain('A/1')->toContain('D1, D2')->toContain('revisar');
});

it('no funde variantes con créditos distintos', function () {
    writeSiiSubjects($this->dir, [
        ['X1', '1', '110100', 'DIBUJO'], ['X2', '1', '110100', 'DIBUJO'],
        ['Y1', '3', '110100', 'DIBUJO AVANZADO'], ['Y2', '3', '110100', 'DIBUJO AVANZADO'],
    ], [
        ['A', '1', 'X1', '4', '2', '2', '1', '', 'A'],
        ['A2', '2', 'X2', '5', '3', '2', '1', '', 'A'],
        ['A', '1', 'Y1', '4', '2', '2', '7', 'E1', 'A'],
        ['A2', '2', 'Y2', '4', '2', '2', '7', 'E2', 'A'],
    ], [], [['E1', 'A', '1', 'PRESENCIAL'], ['E2', 'A2', '2', 'EAD']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(collect($subjects['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->sort()->values()->all())->toBe(['X1', 'X2', 'Y1', 'Y2']);
});

it('funde variantes con horas distintas: queda la materia de la variante presencial con sus horas, y avisa', function () {
    writeSiiSubjects($this->dir, [['P1', '1', '110100', 'CONTABILIDAD FINANCIERA'], ['P2', '1', '110100', 'CONTABILIDAD FINANCIERA']], [
        ['A', '1', 'P1', '4', '4', '0', '2', '', 'A'],
        ['A2', '2', 'P2', '4', '2', '2', '2', '', 'A'],
    ], [['A2', '2', 'P2', 'AEC-1008']]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $placements = collect($run['subjects']['rows'])->where('plan', 'AAA-2010-224');
    $warning = collect($run['subjects']['incidents'])->first(fn (array $i): bool => $i['ref'] === 'AAA-2010-224/P1' && str_contains($i['message'], 'horas distintas'));

    expect($placements->pluck('code')->all())->toBe(['P1'])
        ->and($placements->first())->toMatchArray(['theory_hours' => '4', 'practice_hours' => '0', 'credits' => '4'])
        ->and(collect($run['subject_aliases']['rows'])->keyBy('code')['P2']['subject'])->toBe('P1')
        ->and($warning)->not->toBeNull()
        ->and($warning['message'])->toContain('P1')->toContain('P2')->toContain('presencial')->toContain('supuesto por confirmar');
});

it('una clave del SII con horas distintas en dos planes queda con las del plan más reciente en los dos, y avisa', function () {
    writeSiiSubjects($this->dir, [['Q1', '1', '110100', 'QUIMICA']], [
        ['A', '3', 'Q1', '10', '4', '2', '1', '', 'A'],
        ['C', '1', 'Q1', '8', '3', '2', '1', '', 'A'],
    ]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $hours = ['credits' => '8', 'theory_hours' => '3', 'practice_hours' => '2'];

    expect(subjectsStepRow($subjects, 'AAA-2004-100', 'Q1'))->toMatchArray($hours)
        ->and(subjectsStepRow($subjects, 'CCC-2009-201', 'Q1'))->toMatchArray($hours)
        ->and(subjectsStepMessages($subjects))->toContain('warning|Q1|')->toContain('AAA-2004-100 (10/4/2)')->toContain('queda 8/3/2')->toContain('supuesto por confirmar');
});

it('una clave del SII igual a una clave TecNM es la misma materia y toma las horas de la mayoría', function () {
    writeSiiSubjects($this->dir, [
        ['TI1', '1', '110100', 'TALLER DE INVESTIGACION I'], ['TI2', '1', '110100', 'TALLER DE INVESTIGACION I'], ['ACA-0909', '1', '110100', 'TALLER DE INVESTIGACION I'],
    ], [
        ['A', '1', 'TI1', '4', '0', '4', '6', '', 'A'],
        ['B', '1', 'TI2', '4', '0', '4', '6', '', 'A'],
        ['C', '1', 'ACA-0909', '4', '2', '2', '6', '', 'A'],
    ], [['A', '1', 'TI1', 'ACA-0909'], ['B', '1', 'TI2', 'ACA-0909']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $rows = collect($subjects['rows'])->where('code', 'ACA-0909');

    expect($rows->pluck('plan')->sort()->values()->all())->toBe(['AAA-2010-224', 'BBB-2010-300', 'CCC-2009-201'])
        ->and($rows->map(fn (array $row): string => "{$row['credits']}/{$row['theory_hours']}/{$row['practice_hours']}")->unique()->values()->all())->toBe(['4/0/4'])
        ->and(subjectsStepMessages($subjects))->toContain('CCC-2009-201 (4/2/2)');
});

it('todas las filas de una misma clave traen las mismas horas y créditos', function () {
    $rows = collect(subjectsStepRun($this->dir, $this->curated)['subjects']['rows']);

    $rows->groupBy('code')->each(fn ($group, string $code) => expect($group->map(fn (array $row): string => "{$row['credits']}/{$row['theory_hours']}/{$row['practice_hours']}")->unique())->toHaveCount(1, $code));
});

it('funde variantes cuyo nombre solo cambia de singular a plural', function () {
    writeSiiSubjects($this->dir, [['T1', '1', '110100', 'TUTORIA'], ['T2', '1', '110100', 'TUTORIAS']], [
        ['A', '1', 'T2', '1', '1', '0', '1', '', 'A'],
        ['A2', '2', 'T1', '1', '0', '1', '1', '', 'A'],
    ]);

    $placements = collect(subjectsStepRun($this->dir, $this->curated)['subjects']['rows'])->where('plan', 'AAA-2010-224');

    expect($placements->pluck('code')->all())->toBe(['T2'])
        ->and($placements->first())->toMatchArray(['theory_hours' => '1', 'practice_hours' => '0']);
});

it('recuerda por plan a qué materia de Nexus va cada clave del SII, aunque su alias apunte a otra', function () {
    writeSiiSubjects($this->dir, [['P1', '1', '110100', 'CONTABILIDAD FINANCIERA'], ['P2', '1', '110100', 'CONTABILIDAD FINANCIERA']], [
        ['A', '1', 'P1', '4', '4', '0', '2', '', 'A'],
        ['A2', '2', 'P2', '4', '2', '2', '2', '', 'A'],
        ['B', '1', 'P2', '4', '2', '2', '3', '', 'A'],
    ], [['A2', '2', 'P2', 'AEC-1008'], ['B', '1', 'P2', 'AEC-1008']]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $byPlan = $run['migration']->remember('subject_for_plan', fn () => throw new LogicException('debía estar armado'));
    $subjectFor = $run['migration']->remember('subject_for', fn () => throw new LogicException('debía estar armado'));

    expect($byPlan['AAA-2010-224'])->toBe(['P1' => 'P1', 'P2' => 'P1'])
        ->and($byPlan['BBB-2010-300'])->toBe(['P2' => 'AEC-1008'])
        ->and($subjectFor['P2'])->toBe('AEC-1008');
});

it('no depende del orden de los renglones del SII', function () {
    $planFor = [...SUBJECTS_STEP_PLAN_FOR, 'A3/4' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'mixta']];
    $catalog = [['Q1', '1', '110100', 'DIBUJO'], ['Q2', '1', '110100', 'DIBUJO']];
    $placements = [['A3', '4', 'Q2', '4', '2', '2', '3', '', 'A'], ['A2', '2', 'Q1', '4', '2', '2', '2', '', 'A']];
    $tecnm = [['A3', '4', 'Q2', 'QQQ-0001'], ['A2', '2', 'Q1', 'QQQ-0001']];

    writeSiiSubjects($this->dir, $catalog, $placements, $tecnm);
    $first = subjectsStepRun($this->dir, $this->curated, $planFor);
    writeSiiSubjects($this->dir, array_reverse($catalog), array_reverse($placements), array_reverse($tecnm));
    $second = subjectsStepRun($this->dir, $this->curated, $planFor);

    expect(subjectsStepRow($first['subjects'], 'AAA-2010-224', 'QQQ-0001')['semester'])->toBe('2')
        ->and(subjectsStepMessages($first['subjects']))->toContain('A2/2')
        ->and($second['subjects'])->toBe($first['subjects'])
        ->and($second['subject_aliases'])->toBe($first['subject_aliases']);
});

it('una clave TecNM igual a la clave del SII del lugar no cuenta como clave TecNM, y la variante se funde', function () {
    writeSiiSubjects($this->dir, [['TL1', '1', '110100', 'TALLER DE LIDERAZGO'], ['TL2', '1', '110100', 'TALLER DE LIDERAZGO']], [
        ['A', '1', 'TL1', '4', '2', '2', '2', '', 'A'],
        ['A2', '2', 'TL2', '4', '2', '2', '2', '', 'A'],
    ], [['A', '1', 'TL1', 'INC-1030'], ['A2', '2', 'TL2', 'TL2']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $warning = collect($subjects['incidents'])->first(fn (array $i): bool => $i['ref'] === 'A2/2');

    expect(collect($subjects['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->all())->toBe(['INC-1030'])
        ->and($warning)->not->toBeNull()
        ->and($warning['message'])->toContain('TL2')->toContain('su propia clave del SII')->toContain('supuesto por confirmar');
});

it('avisa si el SII trae dos claves TecNM para el mismo lugar y se queda con la menor', function () {
    writeSiiSubjects($this->dir, SUBJECTS_STEP_CATALOG, SUBJECTS_STEP_PLACEMENTS, [['A', '1', 'CD1', 'ACF-0999'], ...SUBJECTS_STEP_TECNM], SUBJECTS_STEP_SPECIALTIES);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $warning = collect($subjects['incidents'])->first(fn (array $i): bool => $i['ref'] === 'A/1/CD1');

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'ACF-0901'))->not->toBeNull()
        ->and(collect($subjects['rows'])->pluck('code'))->not->toContain('ACF-0999')
        ->and($warning['message'])->toContain('ACF-0901')->toContain('ACF-0999');
});

it('sin los mapas de organigrama y carreras se detiene con un mensaje claro', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), $this->curated);

    expect(fn () => iterator_to_array((new SubjectsStep)->batches($migration)))
        ->toThrow(SiiStepOutOfOrder::class, 'el paso materias necesita que corran antes organigrama y carreras');

    Storage::fake('imports');
    User::factory()->admin()->create(['username' => 'migrador']);
    app()->instance('sii.steps', [OrgChartStep::class, CareersStep::class, SubjectsStep::class]);

    $exit = Artisan::call('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador', '--only' => ['materias']]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('el paso materias necesita que corran antes organigrama y carreras')->not->toContain('error inesperado');
});

it('deja pendiente la materia sin semestre que no empata', function () {
    $run = subjectsStepRun($this->dir, $this->curated);
    $pending = collect($run['subjects']['incidents'])->filter(fn (array $i): bool => $i['ref'] === 'AAA-2004-100/ZZZ');

    expect(collect($run['subjects']['rows'])->pluck('code'))->not->toContain('ZZZ')
        ->and(array_keys($run))->toBe(['migration', 'subjects', 'pending_placements', 'subject_aliases'])
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'ZZZ'))->toMatchArray([
            'name' => 'Materia Sin Par', 'credits' => '4', 'theory_hours' => '2', 'practice_hours' => '2', 'department' => '110100',
            'sii_grid' => 'A/3',
        ])
        ->and($run['pending_placements']['rows'][0])->not->toHaveKey('semester')
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'ZZZ')['suggested_semester'])->toBe('')
        ->and($pending)->toHaveCount(1)
        ->and($pending->first()['level'])->toBe('warning')
        ->and($pending->first()['message'])->toContain('«Por validar»')->toContain('Servicios Escolares')->toContain('sin sugerencia');
});

it('las claves del SII de los pendientes quedan como alias y en subject_for', function () {
    $run = subjectsStepRun($this->dir, $this->curated);

    expect(collect($run['subject_aliases']['rows'])->first(fn (array $row): bool => $row['code'] === 'ZZZ'))
        ->toBe(['code' => 'ZZZ', 'subject' => 'ZZZ', 'plan' => 'AAA-2004-100'])
        ->and($run['migration']->remember('subject_for', fn () => []))->toHaveKey('ZZZ');
});

it('un lugar con estatus N no entra a la retícula: pasa a «Por validar», para no perder el kárdex de quien lo aprobó', function () {
    $run = subjectsStepRun($this->dir, $this->curated);
    $warning = collect($run['subjects']['incidents'])->first(fn (array $i): bool => str_contains($i['ref'], 'NNN'));

    expect(collect($run['subjects']['rows'])->pluck('code'))->not->toContain('NNN')
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2010-224', 'NNN'))->not->toBeNull()
        ->and($warning['message'])->toContain('estatus N')->toContain('«Por validar»')->toContain('supuesto por confirmar');
});

it('D18: en una retícula viva, un lugar con estatus N sugiere su semestre del SII y, si no lo trae, el de su misma carrera', function () {
    writeSiiSubjects($this->dir, [...SUBJECTS_STEP_CATALOG, ['PRW', '3', '110100', 'PROGRAMACION WEB']], [
        ...SUBJECTS_STEP_PLACEMENTS,
        ['A', '3', 'PRW', '5', '2', '3', '0', '', 'N'],
    ], SUBJECTS_STEP_TECNM, SUBJECTS_STEP_SPECIALTIES);

    $run = subjectsStepRun($this->dir, $this->curated);

    expect(subjectsStepRow($run['pending_placements'], 'AAA-2010-224', 'NNN')['suggested_semester'])->toBe('3')
        ->and(subjectsStepRow($run['pending_placements'], 'AAA-2004-100', 'PRW')['suggested_semester'])->toBe('5')
        ->and(collect($run['subjects']['incidents'])->firstWhere('ref', 'AAA-2010-224/NNN')['message'])
        ->toBe('Lugar AAA-2010-224/NNN: el SII lo trae con estatus N (ya no se ofrece): no entra a la retícula; pasa a «Por validar» para que Servicios Escolares decida, con el 3 sugerido, su semestre en el SII. Lo aprobado en él cuenta (supuesto por confirmar).')
        ->and(collect($run['subjects']['incidents'])->firstWhere('ref', 'AAA-2004-100/PRW')['message'])
        ->toContain('con el 5 sugerido por la materia con el mismo nombre de la misma carrera en AAA-2010-224/AEB-1055');
});

it('un lugar con estatus N que es la variante de otro vigente se funde con él', function () {
    writeSiiSubjects($this->dir, [['LOG', '1', '110100', 'LOGISTICA'], ['LOGD', '1', '110100', 'LOGISTICA']], [
        ['A', '1', 'LOG', '4', '2', '2', '9', '', 'A'],
        ['A2', '2', 'LOGD', '4', '2', '2', '9', '', 'N'],
    ]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $forPlan = $run['migration']->remember('subject_for_plan', fn () => throw new LogicException('debía estar armado'));

    expect(subjectsStepRow($run['subjects'], 'AAA-2010-224', 'LOG'))->toMatchArray(['semester' => '9'])
        ->and($run['pending_placements']['rows'])->toBe([])
        ->and($forPlan['AAA-2010-224']['LOGD'])->toBe('LOG');
});

it('una retícula excluida no migra sus materias y avisa una sola vez', function () {
    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];
    $excluded = collect($subjects['incidents'])->filter(fn (array $i): bool => $i['ref'] === 'X/9');

    expect(collect($subjects['rows'])->pluck('code'))->not->toContain('XXM')->not->toContain('XXN')
        ->and($excluded)->toHaveCount(1)
        ->and($excluded->first()['message'])->toContain('2 lugares');
});

/**
 * Prepara la exportación y el curado para correr organigrama, carreras y materias de punta a punta con
 * imports:migrate-sii; con `$selection` escribe también `seleccion_materias` (periodo, número de control).
 *
 * @param  list<array{string, string}>|null  $selection
 */
function subjectsStepEndToEnd(object $test, ?array $selection = null): void
{
    Storage::fake('imports');
    Campus::factory()->create(['code' => 'C1']);
    User::factory()->admin()->create(['username' => 'migrador']);

    $organigrama = base_path('app/Modules/Imports/Sii/Curated/organigrama.csv');
    File::copy($organigrama, $test->curated.'/organigrama.csv');
    File::put($test->curated.'/planes.csv', implode("\n", [
        'sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota',
        'A,1,AAA-2010-224,AAA,presencial,si,0,', 'A2,2,AAA-2010-224,AAA,distancia,si,0,', 'A,3,AAA-2004-100,AAA,presencial,si,0,',
        'B,1,BBB-2010-300,BBB,presencial,si,0,', 'C,1,CCC-2009-201,CCC,presencial,si,0,', 'X,9,XXX-2010-999,XXX,presencial,no,0,sin alumnos',
    ])."\n");

    $areas = array_column((new SiiMigration(SiiExport::open($test->dir), $test->curated))->curated('organigrama'), 'clave_sii');
    File::put($test->dir.'/organigrama.csv', "clave_area,descripcion_area,area_depende,nivel,tipo_area,p_sustantivos,pro_sus,p_sus,p_s\n"
        .implode("\n", array_map(fn (string $area): string => "{$area},AREA {$area},100000,1,X,,,,", $areas))."\n");
    SiiFixtures::write($test->dir.'/carreras.csv', SiiFile::Carreras, array_map(fn (array $c): string => SiiFixtures::line(SiiFile::Carreras, [
        'carrera' => "'{$c[0]}'", 'reticula' => "'{$c[1]}'", 'fecha_inicio' => "'15/08/{$c[2]}'",
    ]), [['A', '1', '2010'], ['A2', '2', '2010'], ['A', '3', '2004'], ['B', '1', '2010'], ['C', '1', '2009'], ['X', '9', '2010']]));
    SiiFixtures::write($test->dir.'/alumnos_todos.csv', SiiFile::Alumnos, []);

    app()->instance('sii.steps', [OrgChartStep::class, CareersStep::class, SubjectsStep::class]);
    app()->when(RunSiiMigration::class)->needs('$curatedDir')->give($test->curated);

    if ($selection !== null) {
        subjectsStepSelection($test->dir, $selection);
    }
}

/**
 * Escribe `seleccion_materias` con solo el periodo y el número de control de cada renglón.
 *
 * @param  list<array{string, string}>  $selection
 */
function subjectsStepSelection(string $dir, array $selection): void
{
    SiiFixtures::write($dir.'/seleccion_materias.csv', SiiFile::SeleccionMaterias, array_map(fn (array $r): string => SiiFixtures::line(SiiFile::SeleccionMaterias, [
        'periodo' => "'{$r[0]}'", 'no_de_control' => "'{$r[1]}'", 'calificacion' => '', 'tipo_evaluacion' => "''",
    ]), $selection));
}

it('migra las materias de punta a punta después del organigrama y las carreras', function () {
    subjectsStepEndToEnd($this);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $plan = fn (string $code): string => StudyPlan::query()->where('code', $code)->value('id');
    $semester = fn (string $planCode, string $subjectCode): ?int => StudyPlanSubject::query()
        ->where('study_plan_id', $plan($planCode))
        ->where('subject_id', Subject::query()->where('code', $subjectCode)->value('id'))
        ->value('semester');
    $calculus = Subject::query()->where('code', 'ACF-0901')->sole();

    expect($calculus->name)->toBe('Cálculo Diferencial')
        ->and($calculus->department->code)->toBe('110100')
        ->and($calculus->kind)->toBe(SubjectKind::Basica)
        ->and($semester('AAA-2010-224', 'ACF-0901'))->toBe(1)
        ->and($semester('AAA-2004-100', 'OLD'))->toBeNull()
        ->and($semester('CCC-2009-201', 'CD3'))->toBe(1)
        ->and(Subject::query()->where('code', 'AEB-1055')->sole()->department->code)->toBe('110700')
        ->and(Subject::query()->where('code', 'ZZZ')->exists())->toBeTrue()
        ->and(StudyPlanSubject::query()->where('subject_id', Subject::query()->where('code', 'ZZZ')->value('id'))->exists())->toBeFalse()
        ->and(StudyPlanSubjectPending::query()->where('study_plan_id', $plan('AAA-2004-100'))->whereNull('resolved_at')->count())->toBe(3)
        ->and(StudyPlanSubjectPending::query()->where('subject_id', Subject::query()->where('code', 'OLD')->value('id'))->sole()->suggested_semester)->toBe(1)
        ->and(StudyPlan::query()->where('is_historical', true)->exists())->toBeFalse()
        ->and(SubjectAlias::query()->where('code', 'ZZZ')->sole()->subject->code)->toBe('ZZZ')
        ->and(SubjectAlias::query()->where('code', 'CD1D')->sole()->subject_id)->toBe($calculus->id)
        ->and(SubjectAlias::query()->where('code', 'CD3')->sole()->subject->code)->toBe('CD3')
        ->and(Specialty::query()->where('code', 'ESP-01')->sole()->name)->toBe('INTELIGENCIA DE NEGOCIOS')
        ->and(ImportBatch::query()->whereIn('template', ['subjects', 'pending_placements', 'subject_aliases'])->orderBy('template')->pluck('error_rows', 'template')->all())
        ->toBe(['pending_placements' => 0, 'subject_aliases' => 0, 'subjects' => 0])
        ->and(file_get_contents($this->dir.'/_incidencias.csv'))->toContain('AAA-2004-100/ZZZ')->toContain('Servicios Escolares');
});

it('D18: una segunda corrida pone o borra el semestre sugerido según la retícula sea viva o histórica', function () {
    subjectsStepEndToEnd($this);
    $suggested = fn (): ?int => StudyPlanSubjectPending::query()->where('subject_id', Subject::query()->where('code', 'OLD')->value('id'))->sole()->suggested_semester;

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();
    expect($suggested())->toBe(1);

    subjectsStepSelection($this->dir, [['20261', 'NADIE']]);
    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();
    expect($suggested())->toBeNull()
        ->and(StudyPlan::query()->where('code', 'AAA-2004-100')->value('is_historical'))->toBeTrue();

    File::delete($this->dir.'/seleccion_materias.csv');
    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();
    expect($suggested())->toBe(1)
        ->and(StudyPlan::query()->where('code', 'AAA-2004-100')->value('is_historical'))->toBeFalse();
});

it('D18: de punta a punta, un plan sin inscritos en el periodo actual queda como retícula histórica y sus materias sin semestre esperan sin sugerencia', function () {
    subjectsStepEndToEnd($this, [['20261', 'NADIE']]);

    $this->artisan('imports:migrate-sii', ['dir' => $this->dir, '--user' => 'migrador'])->assertSuccessful();

    $old = StudyPlan::query()->where('code', 'AAA-2004-100')->sole();

    expect(StudyPlan::query()->where('is_historical', false)->pluck('code')->all())->toBe([])
        ->and($old->placements()->count())->toBe(0)
        ->and($old->pendingPlacements()->whereNull('resolved_at')->count())->toBe(3)
        ->and($old->pendingPlacements()->whereNotNull('suggested_semester')->exists())->toBeFalse()
        ->and(file_get_contents($this->dir.'/_incidencias.csv'))->toContain('Retícula histórica AAA-2004-100')->not->toContain('AAA-2004-100/OLD');
});

it('toma el orden en el semestre (D21) del renglón del SII, de la variante presencial al fundir; 0 queda vacío', function () {
    $placements = array_map(fn (array $p): array => match ($p[2]) {
        'CD1' => [...$p, '3'],
        'CD1D' => [...$p, '5'],
        'ETI' => [...$p, '0'],
        'CD2' => [...$p, '7'],
        default => $p,
    }, SUBJECTS_STEP_PLACEMENTS);
    writeSiiSubjects($this->dir, SUBJECTS_STEP_CATALOG, $placements, SUBJECTS_STEP_TECNM, SUBJECTS_STEP_SPECIALTIES);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(subjectsStepRow($subjects, 'AAA-2010-224', 'ACF-0901')['position'])->toBe('3')
        ->and(subjectsStepRow($subjects, 'BBB-2010-300', 'ACF-0901')['position'])->toBe('7')
        ->and(subjectsStepRow($subjects, 'AAA-2010-224', 'ACA-0907')['position'])->toBe('');
});

it('dos materias distintas de una retícula con la misma clave TecNM no se juntan: la conserva la que se llama como esa clave en otras retículas, y avisa', function () {
    writeSiiSubjects($this->dir, [
        ['PF1', '1', '110100', 'PROCESOS DE FABRICACION'],
        ['PF2', '1', '110100', 'PROCESOS DE FRABRICACION'],
        ['FI2', '1', '110100', 'FISICA'],
    ], [
        ['A', '1', 'PF1', '4', '2', '2', '4', '', 'A'],
        ['A2', '2', 'PF2', '4', '2', '2', '4', '', 'A'],
        ['A2', '2', 'FI2', '4', '2', '2', '4', '', 'A'],
    ], [['A', '1', 'PF1', 'INC-1023'], ['A2', '2', 'PF2', 'INC-1023'], ['A2', '2', 'FI2', 'INC-1023']]);

    $run = subjectsStepRun($this->dir, $this->curated);
    $forPlan = $run['migration']->remember('subject_for_plan', fn () => throw new LogicException('debía estar armado'));
    $warning = collect($run['subjects']['incidents'])->first(fn (array $i): bool => $i['ref'] === 'A2/2');

    expect(collect($run['subjects']['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->sort()->values()->all())->toBe(['FI2', 'INC-1023'])
        ->and($forPlan['AAA-2010-224'])->toMatchArray(['PF1' => 'INC-1023', 'PF2' => 'INC-1023', 'FI2' => 'FI2'])
        ->and($warning['message'])->toContain('INC-1023')->toContain('FI2 (FISICA)')->toContain('la conserva PF2')->toContain('supuesto por confirmar');
});

it('una clave TecNM que en la misma retícula es la clave del SII de otra materia no se junta con ella: cada una conserva la suya', function () {
    writeSiiSubjects($this->dir, [['ECO', '1', '110100', 'ECONOMIA'], ['TFC-0001', '1', '110100', 'TALLER DE FABRICACION DE CIRCUITOS']], [
        ['A', '1', 'ECO', '3', '0', '3', '4', '', 'A'],
        ['A', '1', 'TFC-0001', '3', '0', '3', '5', '', 'A'],
    ], [['A', '1', 'ECO', 'TFC-0001']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(collect($subjects['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->sort()->values()->all())->toBe(['ECO', 'TFC-0001'])
        ->and(subjectsStepMessages($subjects))->toContain('warning|A/1|')->toContain('la conserva TFC-0001');
});

it('una materia que vuelve a su clave del SII y choca con otra de la misma retícula también se separa', function () {
    writeSiiSubjects($this->dir, [['X1', '1', '110100', 'EQUIS'], ['Y1', '1', '110100', 'YE'], ['N1', '1', '110100', 'ENE']], [
        ['A', '1', 'X1', '4', '2', '2', '1', '', 'A'],
        ['A', '1', 'Y1', '4', '2', '2', '2', '', 'A'],
        ['A', '1', 'N1', '4', '2', '2', '3', '', 'A'],
    ], [['A', '1', 'Y1', 'X1'], ['A', '1', 'N1', 'Y1']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(collect($subjects['rows'])->where('plan', 'AAA-2010-224')->pluck('code')->sort()->values()->all())->toBe(['N1', 'X1', 'Y1']);
});

it('la materia que pierde una clave TecNM dentro de su retícula no vota por las horas de esa clave', function () {
    writeSiiSubjects($this->dir, [
        ['PA1', '1', '110100', 'PROCESOS DE FABRICACION'], ['PA2', '1', '110100', 'PROCESOS DE FABRICACION'],
        ['PC1', '1', '110100', 'PROCESOS DE FABRICACION'], ['FC1', '1', '110100', 'FISICA'], ['FB1', '1', '110100', 'FISICA'],
    ], [
        ['A', '1', 'PA1', '4', '2', '2', '4', '', 'A'],
        ['A2', '2', 'PA2', '4', '2', '2', '4', '', 'A'],
        ['C', '1', 'PC1', '4', '2', '2', '4', '', 'A'],
        ['C', '1', 'FC1', '5', '3', '2', '4', '', 'A'],
        ['B', '1', 'FB1', '5', '3', '2', '4', '', 'A'],
    ], [['A', '1', 'PA1', 'INC-1023'], ['A2', '2', 'PA2', 'INC-1023'], ['C', '1', 'PC1', 'INC-1023'], ['C', '1', 'FC1', 'INC-1023'], ['B', '1', 'FB1', 'INC-1023']]);

    $subjects = subjectsStepRun($this->dir, $this->curated)['subjects'];

    expect(subjectsStepRow($subjects, 'CCC-2009-201', 'INC-1023'))->toMatchArray(['credits' => '4'])
        ->and(subjectsStepRow($subjects, 'CCC-2009-201', 'FC1'))->not->toBeNull()
        ->and(subjectsStepRow($subjects, 'BBB-2010-300', 'FB1'))->not->toBeNull()
        ->and(subjectsStepMessages($subjects))->not->toContain('empate');
});
