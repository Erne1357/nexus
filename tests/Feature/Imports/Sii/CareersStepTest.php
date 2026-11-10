<?php

use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiCuratedMismatch;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\CareersStep;
use Illuminate\Support\Facades\File;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `carreras` de imports:migrate-sii (D15): las variantes por modalidad se funden en el plan oficial, la
| oferta por campus sale de los alumnos, el plan actual es el que recibió nuevo ingreso en el último periodo
| regular. Todo con datos sintéticos: carreras y alumnos inventados, y un curado propio en una carpeta temporal.
*/

const SII_CURATED_PLANS = <<<'CSV'
sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota
A,1,AAA-2010-224,AAA,presencial,si,0,
A2,2,AAA-2010-224,AAA,distancia,si,0,
A,3,AAA-2004-100,AAA,presencial,si,0,
B,1,BBB-2010-300,BBB,presencial,si,0,
C,1,CCC-2005-200,CCC,presencial,si,0,
D,1,DDD-2010-1,DDD,presencial,si,0,
D,2,DDD-IS-80-160,DDD,presencial,si,0,
X,9,XXX-2010-999,XXX,presencial,no,0,retícula sin alumnos
CSV;

const SII_CURATED_CAREERS = <<<'CSV'
carrera,nombre,departamento,departamento_nombre,nota
AAA,Carrera Triple A,110600,Sistemas,
BBB,Carrera Doble B,110600,Sistemas,
CCC,Carrera Ce,110500,Eléctrica,
DDD,Carrera De,110500,Eléctrica,
CSV;

/**
 * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $careers  carrera, retícula, fecha de inicio y créditos totales
 * @param  list<array{string, string, string, string, string}>  $students  carrera, retícula, estatus, periodo de ingreso, campus
 */
function writeSiiCareers(string $dir, array $careers, array $students): void
{
    SiiFixtures::write($dir.'/carreras.csv', SiiFile::Carreras, array_map(fn (array $c): string => SiiFixtures::line(SiiFile::Carreras, [
        'carrera' => "'{$c[0]}'", 'reticula' => "'{$c[1]}'", 'fecha_inicio' => "'{$c[2]}'", 'creditos_totales' => "'".($c[3] ?? '')."'",
    ]), $careers));

    SiiFixtures::write($dir.'/alumnos_todos.csv', SiiFile::Alumnos, array_map(fn (array $s, int $i): string => SiiFixtures::line(SiiFile::Alumnos, [
        'no_de_control' => "'S{$i}'", 'carrera' => "'{$s[0]}'", 'reticula' => "'{$s[1]}'", 'estatus_alumno' => "'{$s[2]}'",
        'periodo_ingreso_it' => "'{$s[3]}'", 'campus' => "'{$s[4]}'",
    ]), $students, array_keys($students)));
}

/**
 * `seleccion_materias` con solo el periodo y el número de control de cada renglón.
 *
 * @param  list<array{string, string}>  $rows  periodo, número de control
 */
function writeSiiCareersSelection(string $dir, array $rows): void
{
    SiiFixtures::write($dir.'/seleccion_materias.csv', SiiFile::SeleccionMaterias, array_map(fn (array $r): string => SiiFixtures::line(SiiFile::SeleccionMaterias, [
        'periodo' => "'{$r[0]}'", 'no_de_control' => "'{$r[1]}'", 'calificacion' => '', 'tipo_evaluacion' => "''",
    ]), $rows));
}

/**
 * @return array<string, list<array<string, scalar|null>>>
 */
function careersStepBatches(string $dir, string $curated): array
{
    $migration = new SiiMigration(SiiExport::open($dir), $curated);
    $out = [];

    foreach ((new CareersStep)->batches($migration) as $batch) {
        $out[$batch->template] = ['rows' => array_values([...$batch->rows]), 'incidents' => $batch->incidents];
    }

    return $out;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sii-'.uniqid();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    $this->curated = sys_get_temp_dir().'/sii-curated-'.uniqid();
    File::makeDirectory($this->curated, 0775, true);
    File::put($this->curated.'/planes.csv', SII_CURATED_PLANS);
    File::put($this->curated.'/carreras.csv', SII_CURATED_CAREERS);

    writeSiiCareers($this->dir, [
        ['A', '1', '15/08/2010'], ['A2', '2', '15/08/2010'], ['A', '3', '15/08/2004'],
        ['B', '1', '15/08/2010'], ['C', '1', '15/08/2005'], ['D', '1', '15/08/2010'],
        ['D', '2', '15/08/1980'], ['X', '9', '15/08/2010'],
    ], [
        ['A', '1', 'ACT', '20253', '2'],
        ['A2', '2', 'ACT', '20253', '1'],
        ['A', '3', 'EGR', '20111', '1'],
        ['A', '3', 'EGR', '20254', '1'],
        ['C', '1', 'EGR', '20231', '1'],
        ['D', '1', 'ACT', '20253', '1'],
        ['D', '2', 'ACT', '20253', '1'],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    File::deleteDirectory($this->curated);
});

it('funde IS2/26 e ISC/44 en ISIC-2010-224', function () {
    $curated = base_path('app/Modules/Imports/Sii/Curated');
    $migration = new SiiMigration(SiiExport::open($this->dir), $this->curated);
    iterator_to_array((new CareersStep)->batches($migration));
    $map = $migration->remember('plan_for', fn () => throw new LogicException('debía estar armado'));

    expect($map['A/1'])->toBe(['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'presencial'])
        ->and($map['A2/2'])->toBe(['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'distancia'])
        ->and(array_key_exists('X/9', $map))->toBeFalse();

    $merged = array_filter((new SiiMigration(SiiExport::open($this->dir), $curated))->curated('planes'), fn (array $r): bool => $r['plan'] === 'ISIC-2010-224');

    expect(array_map(fn (array $r): string => $r['sii_carrera'].'/'.$r['sii_reticula'], array_values($merged)))
        ->toEqualCanonicalizing(['IS2/26', 'ISC/44', 'SCN/11']);

    $plans = careersStepBatches($this->dir, $this->curated)['study_plans']['rows'];
    expect(array_count_values(array_column($plans, 'code'))['AAA-2010-224'])->toBe(1);
});

it('deriva la oferta por campus de los alumnos', function () {
    $rows = careersStepBatches($this->dir, $this->curated)['careers']['rows'];
    $offer = array_map(fn (array $r): string => $r['code'].'/'.$r['campus'], $rows);

    expect($offer)->toBe(['AAA/C1', 'AAA/C2', 'BBB/C1', 'CCC/C1', 'DDD/C1'])
        ->and($rows[0])->toMatchArray(['name' => 'Carrera Triple A', 'department' => '110600']);
});

it('una carrera sin alumnos se ofrece en C1 con advertencia', function () {
    $batch = careersStepBatches($this->dir, $this->curated)['careers'];

    expect(array_values(array_filter($batch['rows'], fn (array $r): bool => $r['code'] === 'BBB')))->toHaveCount(1)
        ->and(collect($batch['incidents'])->where('level', 'warning')->pluck('message')->implode('|'))
        ->toContain('BBB')->toContain('carrera sin alumnos: se ofrece en C1 (supuesto por confirmar)');
});

it('marca como actual el plan con nuevo ingreso', function () {
    $plans = collect(careersStepBatches($this->dir, $this->curated)['study_plans']['rows'])->keyBy('code');

    expect($plans['AAA-2010-224'])->toMatchArray(['career' => 'AAA', 'name' => '', 'version' => '2010', 'current' => 'Sí', 'intake' => 'Sí'])
        ->and($plans['AAA-2004-100'])->toMatchArray(['version' => '2004', 'intake' => 'No'])
        ->and($plans->count())->toBe(6);
});

it('recuerda la versión de cada plan para los pasos siguientes', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), $this->curated);
    iterator_to_array((new CareersStep)->batches($migration));

    expect($migration->remember('plan_version', fn () => throw new LogicException('debía estar armado')))
        ->toMatchArray(['AAA-2010-224' => '2010', 'AAA-2004-100' => '2004', 'DDD-IS-80-160' => '1980'])
        ->not->toHaveKey('XXX-2010-999');
});

it('marca como no vigente un plan sin alumnos activos', function () {
    $plans = collect(careersStepBatches($this->dir, $this->curated)['study_plans']['rows'])->keyBy('code');

    expect($plans['AAA-2004-100']['current'])->toBe('No');
});

it('sin nuevo ingreso en el último periodo no hay plan actual y lo reporta', function () {
    $batch = careersStepBatches($this->dir, $this->curated)['study_plans'];
    $plan = collect($batch['rows'])->firstWhere('code', 'CCC-2005-200');
    $messages = collect($batch['incidents'])->where('level', 'warning')->pluck('message')->implode('|');

    expect($plan)->toMatchArray(['current' => 'No', 'intake' => 'No'])
        ->and($messages)->toContain('CCC')->toContain('20253');
});

it('un empate de nuevo ingreso entre dos planes no marca ninguno y lo reporta', function () {
    $batch = careersStepBatches($this->dir, $this->curated)['study_plans'];
    $plans = collect($batch['rows'])->whereIn('code', ['DDD-2010-1', 'DDD-IS-80-160'])->keyBy('code');

    expect($plans['DDD-2010-1']['intake'])->toBe('No')
        ->and($plans['DDD-IS-80-160']['intake'])->toBe('No')
        ->and($plans['DDD-IS-80-160']['version'])->toBe('1980')
        ->and(collect($batch['incidents'])->pluck('message')->implode('|'))->toContain('DDD');
});

it('excluye las retículas marcadas y lo reporta', function () {
    $batch = careersStepBatches($this->dir, $this->curated)['careers'];
    $excluded = collect($batch['incidents'])->first(fn (array $i): bool => str_contains($i['ref'].$i['message'], 'X/9'));

    expect($excluded)->not->toBeNull()
        ->and($excluded['level'])->toBe('warning')
        ->and($excluded['message'])->toContain('retícula sin alumnos')
        ->and(collect(careersStepBatches($this->dir, $this->curated)['study_plans']['rows'])->pluck('code'))->not->toContain('XXX-2010-999');
});

it('una carrera+retícula del SII sin renglón curado detiene la migración', function () {
    writeSiiCareers($this->dir, [['A', '1', '15/08/2010'], ['Q', '7', '15/08/2010']], []);
    File::put($this->curated.'/planes.csv', "sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota\nA,1,AAA-2010-224,AAA,presencial,si,0,\nZ,5,ZZZ-2010-1,ZZZ,presencial,si,0,\n");

    expect(fn () => careersStepBatches($this->dir, $this->curated))
        ->toThrow(SiiCuratedMismatch::class, 'Q/7');
    expect(fn () => careersStepBatches($this->dir, $this->curated))
        ->toThrow(SiiCuratedMismatch::class, 'Z/5');
});

it('una carrera incluida sin renglón en carreras.csv detiene la migración', function () {
    File::put($this->curated.'/carreras.csv', "carrera,nombre,departamento,departamento_nombre,nota\nAAA,Carrera Triple A,110600,Sistemas,\n");

    expect(fn () => careersStepBatches($this->dir, $this->curated))->toThrow(SiiCuratedMismatch::class, 'BBB');
});

it('un campus desconocido se reporta y no genera oferta', function () {
    writeSiiCareers($this->dir, [['A', '1', '15/08/2010'], ['A2', '2', '15/08/2010'], ['A', '3', '15/08/2004'], ['B', '1', '15/08/2010'], ['C', '1', '15/08/2005'], ['D', '1', '15/08/2010'], ['D', '2', '15/08/1980'], ['X', '9', '15/08/2010']], [
        ['A', '1', 'ACT', '20253', '7'],
    ]);
    $batch = careersStepBatches($this->dir, $this->curated)['careers'];

    expect(array_column($batch['rows'], 'campus'))->not->toContain('7')
        ->and(collect($batch['incidents'])->where('level', 'warning')->pluck('message')->implode('|'))->toContain('campus');
});

it('el curado real cubre cada carrera incluida', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), base_path('app/Modules/Imports/Sii/Curated'));
    $careers = array_column($migration->curated('carreras'), 'carrera');
    $included = array_unique(array_column(array_filter($migration->curated('planes'), fn (array $r): bool => $r['incluir'] === 'si'), 'carrera'));

    expect(array_diff($included, $careers))->toBe([]);
});

it('lleva al plan los créditos totales de su retícula del SII; si sus retículas difieren, quedan los de la presencial, con aviso', function () {
    writeSiiCareers($this->dir, [
        ['A2', '2', '15/08/2010', '250'], ['A', '1', '15/08/2010', '260'], ['A', '3', '15/08/2004', '440.00'],
        ['B', '1', '15/08/2010', ''], ['C', '1', '15/08/2005', '0'], ['D', '1', '15/08/2010', '260'], ['D', '1', '15/08/2010', '250'],
        ['D', '2', '15/08/1980', '380'], ['X', '9', '15/08/2010', '260'],
    ], [['A', '1', 'ACT', '20253', '2']]);

    $batch = careersStepBatches($this->dir, $this->curated)['study_plans'];
    $plans = array_column($batch['rows'], null, 'code');

    expect($plans['AAA-2010-224']['credits'])->toBe('260')
        ->and($plans['AAA-2004-100']['credits'])->toBe('440')
        ->and($plans['BBB-2010-300']['credits'])->toBeNull()
        ->and($plans['CCC-2005-200']['credits'])->toBeNull()
        ->and($plans['DDD-IS-80-160']['credits'])->toBe('380')
        ->and($plans['DDD-2010-1']['credits'])->toBe('260')
        ->and(collect($batch['incidents'])->pluck('message')->filter(fn (string $m): bool => str_contains($m, 'créditos'))->values()->all())
        ->toBe([
            'El plan AAA-2010-224 junta retículas del SII con créditos totales distintos (A/1 260, A2/2 250): quedan 260, de la presencial (supuesto por confirmar).',
            'La retícula D/1 del SII viene varias veces en carreras con créditos totales distintos (250, 260): quedan 260, los mayores (supuesto por confirmar).',
        ]);
});

/**
 * Corre el paso y devuelve la migración y sus lotes por plantilla.
 *
 * @return array{migration: SiiMigration, batches: array<string, SiiBatch>}
 */
function careersStepRun(string $dir, string $curated): array
{
    $migration = new SiiMigration(SiiExport::open($dir), $curated);
    $batches = [];
    foreach ((new CareersStep)->batches($migration) as $batch) {
        $batches[$batch->template] = $batch;
    }

    return ['migration' => $migration, 'batches' => $batches];
}

/**
 * @param  array{migration: SiiMigration, batches: array<string, SiiBatch>}  $run
 * @return list<string>
 */
function careersStepPeriodWarnings(array $run): array
{
    return collect($run['batches']['study_plans']->incidents)->where('ref', 'seleccion_materias')->pluck('message')->values()->all();
}

it('D18: un plan sin alumnos inscritos en el periodo regular más reciente y que no es vigente es una retícula histórica; el verano no cuenta, y un plan con dos retículas del SII es vivo si una tiene inscritos', function () {
    // 20253: S0 (A/1), S2 (A/3) y S4 (C/1); 20261: S1 (A2/2) y S5 (D/1); S2 también en el verano 20262; S6 (D/2) con un periodo que no es del SII.
    writeSiiCareersSelection($this->dir, [['20253', 'S0'], ['20253', 'S2'], ['20261', 'S1'], ['20253', 'S4'], ['20261', 'S5'], ['20264', 'S6'], ['20261', 'S1'], ['20262', 'S2']]);
    $run = careersStepRun($this->dir, $this->curated);
    $plans = array_column([...$run['batches']['study_plans']->rows], 'historical', 'code');

    expect($plans)->toBe([
        'AAA-2004-100' => 'Sí', 'AAA-2010-224' => 'No', 'BBB-2010-300' => 'Sí', 'CCC-2005-200' => 'Sí', 'DDD-2010-1' => 'No', 'DDD-IS-80-160' => 'No',
    ])
        ->and($run['migration']->remember('historical_plans', fn () => throw new LogicException('debía estar armado')))
        ->toBe(['AAA-2004-100' => true, 'BBB-2010-300' => true, 'CCC-2005-200' => true])
        ->and(careersStepPeriodWarnings($run))
        ->toBe(['El periodo actual es el 20261 (2 alumnos inscritos en seleccion_materias): 3 de 6 planes no tienen alumnos inscritos en él ni son vigentes, y quedan como retículas históricas (confirmado, 10 de noviembre de 2026).']);
});

it('D18: un periodo regular con menos de la mitad de alumnos que el anterior todavía se está abriendo: cuentan los inscritos en los dos', function () {
    writeSiiCareersSelection($this->dir, [['20253', 'S2'], ['20253', 'S4'], ['20253', 'S0'], ['20271', 'S1'], ['20262', 'S6']]);
    $run = careersStepRun($this->dir, $this->curated);

    expect($run['migration']->remember('historical_plans', fn () => throw new LogicException('debía estar armado')))->toBe(['BBB-2010-300' => true])
        ->and(careersStepPeriodWarnings($run))
        ->toBe(['El periodo 20271 (1 alumno inscrito en seleccion_materias) todavía se está abriendo, con menos de la mitad que el 20253 (3 alumnos): cuentan los inscritos en los dos (umbral de la mitad, supuesto por confirmar). 1 de 6 planes no tienen alumnos inscritos ni son vigentes, y quedan como retículas históricas (confirmado, 10 de noviembre de 2026).']);

    writeSiiCareersSelection($this->dir, [['20253', 'S2'], ['20253', 'S4'], ['20253', 'S0'], ['20271', 'S1'], ['20271', 'S5']]);

    expect(careersStepRun($this->dir, $this->curated)['migration']->remember('historical_plans', fn () => throw new LogicException('debía estar armado')))
        ->toBe(['AAA-2004-100' => true, 'BBB-2010-300' => true, 'CCC-2005-200' => true]);
});

it('D18: un plan vigente o de nuevo ingreso nunca es una retícula histórica, aunque nadie esté inscrito todavía', function () {
    // S0: egresado de B/1 que entró en 20253, así que BBB-2010-300 es el de nuevo ingreso sin alumnos activos; S1: activo de D/2.
    writeSiiCareers($this->dir, [
        ['A', '1', '15/08/2010'], ['A2', '2', '15/08/2010'], ['A', '3', '15/08/2004'], ['B', '1', '15/08/2010'],
        ['C', '1', '15/08/2005'], ['D', '1', '15/08/2010'], ['D', '2', '15/08/1980'], ['X', '9', '15/08/2010'],
    ], [['B', '1', 'EGR', '20253', '1'], ['D', '2', 'ACT', '20231', '1']]);
    writeSiiCareersSelection($this->dir, [['20261', 'S9']]);
    $run = careersStepRun($this->dir, $this->curated);
    $plans = collect([...$run['batches']['study_plans']->rows])->keyBy('code');

    expect($plans['BBB-2010-300'])->toMatchArray(['current' => 'Sí', 'intake' => 'Sí', 'historical' => 'No'])
        ->and($plans['DDD-IS-80-160'])->toMatchArray(['current' => 'Sí', 'intake' => 'No', 'historical' => 'No'])
        ->and($run['migration']->remember('historical_plans', fn () => throw new LogicException('debía estar armado')))
        ->toBe(['AAA-2004-100' => true, 'AAA-2010-224' => true, 'CCC-2005-200' => true, 'DDD-2010-1' => true]);
});

it('D18: un plan que junta dos retículas del SII es vivo si una de ellas tiene un inscrito, aunque no sea vigente ni de nuevo ingreso', function () {
    // S0 (A/1) y S1 (A2/2), los dos de AAA-2010-224, egresados y sin periodo de ingreso: el plan no es vigente ni de nuevo ingreso.
    writeSiiCareers($this->dir, [
        ['A', '1', '15/08/2010'], ['A2', '2', '15/08/2010'], ['A', '3', '15/08/2004'], ['B', '1', '15/08/2010'],
        ['C', '1', '15/08/2005'], ['D', '1', '15/08/2010'], ['D', '2', '15/08/1980'], ['X', '9', '15/08/2010'],
    ], [['A', '1', 'EGR', '', '1'], ['A2', '2', 'EGR', '', '1']]);
    $plan = fn (): array => collect([...careersStepRun($this->dir, $this->curated)['batches']['study_plans']->rows])->firstWhere('code', 'AAA-2010-224');

    writeSiiCareersSelection($this->dir, [['20261', 'S1']]);
    expect($plan())->toMatchArray(['current' => 'No', 'intake' => 'No', 'historical' => 'No']);

    writeSiiCareersSelection($this->dir, [['20261', 'S9']]);
    expect($plan())->toMatchArray(['current' => 'No', 'intake' => 'No', 'historical' => 'Sí']);
});

it('solo lee de la selección el periodo y el número de control', function () {
    expect(CareersStep::SELECTION_COLUMNS)->toEqualCanonicalizing(['periodo', 'no_de_control']);
});

it('D18: sin seleccion_materias ningún plan es histórico, y lo avisa una vez', function () {
    $migration = new SiiMigration(SiiExport::open($this->dir), $this->curated);
    $batches = [];
    foreach ((new CareersStep)->batches($migration) as $batch) {
        $batches[$batch->template] = $batch;
    }
    $messages = collect([...$batches['careers']->incidents, ...$batches['study_plans']->incidents])->pluck('message')->filter(fn (string $m): bool => str_contains($m, 'seleccion_materias'));

    expect(array_unique(array_column([...$batches['study_plans']->rows], 'historical')))->toBe(['No'])
        ->and($migration->remember('historical_plans', fn () => throw new LogicException('debía estar armado')))->toBe([])
        ->and($messages->values()->all())->toBe(['La exportación no trae seleccion_materias con periodos regulares válidos: no se sabe quién está inscrito en el periodo actual, así que ningún plan se trata como retícula histórica (D18).']);

    writeSiiCareersSelection($this->dir, [['20262', 'S1'], ['2026', 'S5']]);

    expect(careersStepRun($this->dir, $this->curated)['migration']->remember('historical_plans', fn () => throw new LogicException('debía estar armado')))->toBe([]);
});
