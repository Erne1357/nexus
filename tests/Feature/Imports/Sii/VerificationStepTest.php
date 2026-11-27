<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Imports\Actions\ArchiveSiiExport;
use App\Modules\Imports\Enums\VerificationDiagnosis;
use App\Modules\Imports\Sii\SiiBatchReport;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\Steps\VerificationStep;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `verificacion` de imports:migrate-sii (decisión del 10 de noviembre de 2026, confirmada: cada corrida compara el
| original con Nexus y avisa si algo no cuadra). Compara el archivo `sii_archivo` de la corrida con el kárdex y el avance
| de Nexus y deja el resultado en el esquema `sii_verificacion`. Datos sintéticos: el archivo sale de CSV inventados y
| Nexus, de factories.
*/

/**
 * Escribe en la exportación de prueba los alumnos, las retículas y la historia del SII, y la guarda en `sii_archivo`.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $students  número de control, retícula (`A/1`) y estatus
 * @param  list<array{0: string, 1: string, 2: string}>  $grid  retícula, clave del SII y créditos
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4?: string}>  $history  periodo, número de control, clave, estatus y calificación
 * @param  list<array{0: string, 1: string}>  $totals  renglones de `carreras`: retícula y créditos totales (una retícula puede repetirse)
 */
function archiveVerificationExport(string $dir, array $students, array $grid, array $history, array $totals): void
{
    $split = fn (string $key): array => explode('/', $key);

    SiiFixtures::write("{$dir}/alumnos_todos.csv", SiiFile::Alumnos, array_map(fn (array $s): string => SiiFixtures::line(SiiFile::Alumnos, [
        'no_de_control' => "'{$s[0]}'", 'carrera' => "'{$split($s[1])[0]}'", 'reticula' => "'{$split($s[1])[1]}'", 'estatus_alumno' => "'{$s[2]}'",
    ]), $students));
    SiiFixtures::write("{$dir}/carreras.csv", SiiFile::Carreras, array_map(fn (array $t): string => SiiFixtures::line(SiiFile::Carreras, [
        'carrera' => "'{$split($t[0])[0]}'", 'reticula' => "'{$split($t[0])[1]}'", 'creditos_totales' => $t[1],
    ]), $totals));
    SiiFixtures::write("{$dir}/materias_carreras.csv", SiiFile::MateriasCarreras, array_map(fn (array $g): string => SiiFixtures::line(SiiFile::MateriasCarreras, [
        'carrera' => "'{$split($g[0])[0]}'", 'reticula' => $split($g[0])[1], 'materia' => "'{$g[1]}'", 'creditos_materia' => $g[2],
    ]), $grid));
    SiiFixtures::write("{$dir}/historia_alumno.csv", SiiFile::HistoriaAlumno, array_map(fn (array $h): string => SiiFixtures::line(SiiFile::HistoriaAlumno, [
        'periodo' => "'{$h[0]}'", 'no_de_control' => "'{$h[1]}'", 'materia' => "'{$h[2]}'", 'estatus_materia' => "'{$h[3]}'", 'calificacion' => $h[4] ?? '',
    ]), $history));

    app(ArchiveSiiExport::class)->handle(SiiExport::open($dir));
}

/**
 * Corre el paso como lo corre la migración: con los mapas de claves, las retículas que se migran (solo la A/1) y lo que
 * dejaron los pasos anteriores de la corrida.
 *
 * @param  list<string>  $done  marcas de los pasos que ya corrieron (`archived`, `kardex_applied`)
 * @return list<SiiBatchReport>
 */
function runVerificationStep(string $dir, bool $dryRun = false, array $done = ['archived', 'kardex_applied']): array
{
    $migration = new SiiMigration(SiiExport::open($dir), '/nonexistent', $dryRun);
    $migration->remember('subject_for', fn (): array => ['CD1' => 'ACF-0901', 'CD2' => 'ACF-0901', 'QU1' => 'AEF-1001', 'PE1' => 'PEN-0001', 'DI1' => 'DIF-0001', 'OU1' => 'OUT-0001', 'OU2' => 'OUT-0001', 'QU9' => 'AEF-1001']);
    $migration->remember('subject_for_plan', fn (): array => ['AAA-2010-224' => ['CD1' => 'ACF-0901', 'CD2' => 'ACF-0901', 'QU1' => 'AEF-1001', 'PE1' => 'PEN-0001', 'DI1' => 'DIF-0001']]);
    $migration->remember('plan_for', fn (): array => ['A/1' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'presencial']]);

    foreach ($done as $flag) {
        $migration->remember($flag, fn (): bool => true);
    }

    return array_values([...app(VerificationStep::class)->batches($migration)]);
}

/**
 * Las incidencias de los reportes del paso como `nivel ref mensaje`.
 *
 * @param  list<SiiBatchReport>  $reports
 * @return list<string>
 */
function verificationIncidents(array $reports, ?string $level = null): array
{
    $lines = [];

    foreach ($reports as $report) {
        foreach ($report->incidents as $incident) {
            if ($level === null || $incident['level'] === $level) {
                $lines[] = "{$incident['level']} {$incident['ref']} {$incident['message']}";
            }
        }
    }

    return $lines;
}

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);

    // Nexus: un plan de 10 créditos con tres materias en la retícula y una pendiente («Por validar»).
    $plan = StudyPlan::factory()->create(['code' => 'AAA-2010-224', 'total_credits' => 10]);
    $subjects = collect(['ACF-0901' => 5, 'AEF-1001' => 5, 'PEN-0001' => 4, 'OUT-0001' => 3, 'DIF-0001' => 4])
        ->map(fn (int $credits, string $code): Subject => Subject::factory()->create(['code' => $code, 'credits' => $credits]));

    foreach (['ACF-0901' => 1, 'AEF-1001' => 2, 'DIF-0001' => 3] as $code => $semester) {
        StudyPlanSubject::factory()->create(['study_plan_id' => $plan->id, 'subject_id' => $subjects[$code]->id, 'semester' => $semester]);
    }
    StudyPlanSubjectPending::factory()->create(['study_plan_id' => $plan->id, 'subject_id' => $subjects['PEN-0001']->id]);

    foreach (['S1', 'S2', 'S3'] as $control) {
        Student::factory()->create(['control_number' => $control, 'study_plan_id' => $plan->id]);
    }

    $this->kardex = fn (string $control, string $subject, ?string $term, string $siiCode, string $result = 'aprobada'): KardexRecord => KardexRecord::factory()->create([
        'student_id' => Student::query()->where('control_number', $control)->value('id'),
        'subject_id' => $subjects[$subject]->id,
        'term_code' => $term,
        'result' => $result,
        'grade' => $result === 'aprobada' ? 90 : 50,
        'sii_subject_code' => $siiCode,
    ]);

    // SII: la retícula A/1 (el plan AAA-2010-224) pide 10 créditos; OU1 y QU9 solo están en la A/3, que no se migra.
    $this->students = [['S1', 'A/1', 'EGR'], ['S2', 'A/1', 'EGR'], ['S3', 'A/1', 'ACT'], ['S9', 'A/1', 'EGR']];
    $this->grid = [['A/1', 'CD1', '5'], ['A/1', 'CD2', '5'], ['A/1', 'QU1', '5'], ['A/1', 'PE1', '4'], ['A/1', 'DI1', '5'], ['A/3', 'OU1', '3'], ['A/3', 'QU9', '5']];
    $this->totals = [['A/1', '10'], ['A/3', '10']];
    $this->history = [
        ['20251', 'S1', 'CD1', 'A'],   // 2: igual
        ['20251', 'S1', 'QU1', 'A'],   // 3: igual
        ['19001', 'S1', 'PE1', 'A'],   // 4: periodo que no existe, en Nexus sin periodo; pendiente del plan
        ['20253', 'S1', 'OU1', 'A'],   // 5: fuera de la retícula del plan
        ['20253', 'S1', 'DI1', 'A'],   // 6: 5 créditos en el SII, 4 en Nexus
        ['20251', 'S2', 'CD1', 'A'],   // 7: CD1 y CD2 son ACF-0901
        ['20253', 'S2', 'CD2', 'A'],   // 8
        ['20251', 'S9', 'CD1', 'A'],   // 9: S9 no está en Nexus
        ['20261', 'S3', 'CD1', 'R', '60'],   // 10: igual
        ['20261', 'S3', 'CD2', 'R', '50'],   // 11: también es ACF-0901 en 20261, y la 10 tiene mejor calificación
        ['20261', 'S3', 'XYZ', 'A'],   // 12: XYZ no está en ninguna retícula
        ['20261', 'S3', 'QU1', 'A'],   // 13: no llegó y no se sabe por qué
        ['20263', 'S3', 'QU1', 'A'],   // 14: tampoco
        ['20253', 'S3', 'QU9', 'A'],   // 15: QU9 no está en la retícula A/1, pero llega a AEF-1001, que sí está en el plan
    ];

    ($this->kardex)('S1', 'ACF-0901', '20251', 'CD1');
    ($this->kardex)('S1', 'AEF-1001', '20251', 'QU1');
    ($this->kardex)('S1', 'PEN-0001', null, 'PE1');
    ($this->kardex)('S1', 'OUT-0001', '20253', 'OU1');
    ($this->kardex)('S1', 'DIF-0001', '20253', 'DI1');
    ($this->kardex)('S2', 'ACF-0901', '20251', 'CD1');
    ($this->kardex)('S2', 'ACF-0901', '20253', 'CD2');
    ($this->kardex)('S3', 'ACF-0901', '20261', 'CD1', 'reprobada');
    ($this->kardex)('S3', 'AEF-1001', '20253', 'QU9');

    $this->archive = fn () => archiveVerificationExport($this->dir, $this->students, $this->grid, $this->history, $this->totals);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('da a cada renglón de la historia el diagnóstico que le toca', function () {
    ($this->archive)();
    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->orderBy('_linea')->pluck('diagnostico', '_linea')->all())->toBe([
        2 => VerificationDiagnosis::Same->value,
        3 => VerificationDiagnosis::Same->value,
        4 => VerificationDiagnosis::PlanPending->value,
        5 => VerificationDiagnosis::OutsidePlan->value,
        6 => VerificationDiagnosis::CreditsDiffer->value,
        7 => VerificationDiagnosis::SameSubject->value,
        8 => VerificationDiagnosis::SameSubject->value,
        9 => VerificationDiagnosis::StudentMissing->value,
        10 => VerificationDiagnosis::Same->value,
        11 => VerificationDiagnosis::KeptOther->value,
        12 => VerificationDiagnosis::UnknownKey->value,
        13 => VerificationDiagnosis::Unexplained->value,
        14 => VerificationDiagnosis::Unexplained->value,
        15 => VerificationDiagnosis::OutsideSiiGrid->value,
    ])
        ->and(array_column(VerificationDiagnosis::cases(), 'value'))->toBe([
            'igual',
            'pendiente del plan: cuenta',
            'fuera de la retícula del plan: no cuenta',
            'otra clave del SII es la misma materia: cuenta una vez',
            'fuera de la retícula del SII, en el plan de Nexus: cuenta en Nexus',
            'créditos distintos',
            'alumno no está en Nexus',
            'no pasó: el mismo alumno tiene la misma materia de Nexus en el mismo periodo (se queda uno)',
            'no pasó: la clave del SII no está en ninguna retícula',
            'no pasó: sin explicar',
        ]);
});

it('guarda de cada renglón el alumno, el periodo, la clave del SII y la materia y el plan de Nexus a los que llegó', function () {
    ($this->archive)();
    runVerificationStep($this->dir);

    $rows = DB::table('sii_verificacion.materias')->orderBy('_linea')->get()->keyBy('_linea');

    expect((array) $rows[2])->toMatchArray([
        'no_de_control' => 'S1', 'periodo' => '20251', 'periodo_nexus' => '20251', 'clave_sii' => 'CD1', 'estatus_sii' => 'A', 'reticula_sii' => 'A/1',
        'plan_nexus' => 'AAA-2010-224', 'clave_nexus' => 'ACF-0901', 'resultado_nexus' => 'aprobada', 'clave_nexus_esperada' => 'ACF-0901',
    ])
        ->and((float) $rows[2]->creditos_sii)->toBe(5.0)
        ->and((int) $rows[2]->creditos_nexus)->toBe(5)
        ->and($rows[11]->clave_nexus)->toBeNull()
        ->and($rows[11]->clave_nexus_esperada)->toBe('ACF-0901')
        ->and($rows[5]->creditos_sii)->toBeNull()
        ->and($rows[9]->plan_nexus)->toBeNull();
});

it('une el renglón de un periodo que no existe con la fila sin periodo del kárdex: no sale como «no pasó»', function () {
    $this->history = [['19001', 'S1', 'PE1', 'A'], ['0', 'S1', 'QU1', 'A'], ['20251', 'S1', 'CD1', 'A']];
    ($this->kardex)('S1', 'AEF-1001', null, 'QU1');
    ($this->archive)();

    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->orderBy('_linea')->get(['periodo', 'periodo_nexus', 'clave_nexus', 'diagnostico'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['periodo' => '19001', 'periodo_nexus' => null, 'clave_nexus' => 'PEN-0001', 'diagnostico' => VerificationDiagnosis::PlanPending->value],
        ['periodo' => '0', 'periodo_nexus' => null, 'clave_nexus' => 'AEF-1001', 'diagnostico' => VerificationDiagnosis::Same->value],
        ['periodo' => '20251', 'periodo_nexus' => '20251', 'clave_nexus' => 'ACF-0901', 'diagnostico' => VerificationDiagnosis::Same->value],
    ]);
});

it('compara por alumno los créditos según el SII y según Nexus: completo en los dos no da error', function () {
    ($this->archive)();
    $reports = runVerificationStep($this->dir);

    $students = DB::table('sii_verificacion.alumnos')->orderBy('no_de_control')->get()->keyBy('no_de_control');

    expect($students->keys()->all())->toBe(['S1', 'S2', 'S3', 'S9'])
        ->and((float) $students['S1']->creditos_sii)->toBe(19.0)
        ->and((int) $students['S1']->creditos_nexus)->toBe(18)
        ->and($students['S1']->completo_sii)->toBeTrue()
        ->and($students['S1']->completo_nexus)->toBeTrue()
        ->and((float) $students['S1']->creditos_perdidos)->toBe(1.0)
        ->and($students['S9']->en_nexus)->toBeFalse()
        ->and($students['S9']->completo_nexus)->toBeNull()
        ->and($students['S3']->completo_sii)->toBeFalse()
        ->and($students['S3']->completo_nexus)->toBeFalse();

    foreach (verificationIncidents($reports, 'error') as $line) {
        expect($line)->not->toStartWith('error S1 ')->not->toStartWith('error S3 ')->not->toStartWith('error S9 ');
    }
});

it('da error por cada alumno completo en el SII e incompleto en Nexus, con su número de control', function () {
    ($this->archive)();
    $reports = runVerificationStep($this->dir);

    $students = DB::table('sii_verificacion.alumnos')->get()->keyBy('no_de_control');

    expect($students['S2']->completo_sii)->toBeTrue()
        ->and($students['S2']->completo_nexus)->toBeFalse()
        ->and((float) $students['S2']->creditos_sii)->toBe(10.0)
        ->and((int) $students['S2']->creditos_nexus)->toBe(5)
        ->and(array_values(array_filter(verificationIncidents($reports, 'error'), fn (string $line): bool => str_starts_with($line, 'error S'))))->toBe([
            'error S2 Completo en el SII (10 de 10 créditos de la retícula A/1) e incompleto en Nexus (5 de 10 créditos del plan AAA-2010-224): Nexus cuenta 5 créditos menos que el SII.',
        ])
        ->and($reports[1]->file)->toBe('alumnos')
        ->and($reports[1]->errors)->toBe(1);
});

it('en el SII cuenta una vez la materia aprobada dos veces y no cuenta la que no está en la retícula del alumno', function () {
    $this->history = [['20243', 'S1', 'CD1', 'A'], ['20251', 'S1', 'CD1', 'A'], ['20253', 'S1', 'OU1', 'A'], ['20253', 'S1', 'QU1', 'R']];
    ($this->archive)();

    runVerificationStep($this->dir);

    $student = DB::table('sii_verificacion.alumnos')->where('no_de_control', 'S1')->sole();

    expect((float) $student->creditos_sii)->toBe(5.0)
        ->and((int) $student->materias_sii)->toBe(1)
        ->and($student->completo_sii)->toBeFalse();
});

it('agrupa por clave del SII los renglones que no pasaron sin explicación, con error', function () {
    ($this->archive)();
    $reports = runVerificationStep($this->dir);

    expect(array_values(array_filter(verificationIncidents($reports, 'error'), fn (string $line): bool => ! str_starts_with($line, 'error S'))))->toBe([
        'error QU1 2 renglones de la historia con la clave QU1 no pasaron al kárdex de Nexus y no se sabe por qué (líneas 13, 14): revisa sii_verificacion.materias.',
    ])
        ->and($reports[0]->file)->toBe('materias')
        ->and($reports[0]->errors)->toBe(2);
});

it('advierte de los créditos distintos solo si la materia está en las dos retículas, agrupados por materia', function () {
    $this->history[] = ['20263', 'S1', 'DI1', 'R'];
    ($this->kardex)('S1', 'DIF-0001', '20263', 'DI1', 'reprobada');
    ($this->archive)();
    $reports = runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->where('diagnostico', VerificationDiagnosis::CreditsDiffer->value)->orderBy('_linea')->pluck('_linea')->all())->toBe([6, 16])
        ->and(array_values(preg_grep('/^warning DI1 /', verificationIncidents($reports, 'warning'))))->toBe([
            'warning DI1 La clave del SII DI1 llegó a DIF-0001, que en Nexus tiene 4 créditos y en la retícula del alumno en el SII 5: 2 renglones de la historia.',
        ]);
});

it('la clave fuera de la retícula del SII del alumno que llega a una materia de su plan en Nexus es otro diagnóstico, con advertencia por clave', function () {
    ($this->archive)();
    $reports = runVerificationStep($this->dir);

    $row = DB::table('sii_verificacion.materias')->where('_linea', 15)->sole();

    expect($row->diagnostico)->toBe(VerificationDiagnosis::OutsideSiiGrid->value)
        ->and($row->creditos_sii)->toBeNull()
        ->and($row->clave_nexus)->toBe('AEF-1001')
        ->and(array_values(preg_grep('/^warning QU9 /', verificationIncidents($reports, 'warning'))))->toBe([
            'warning QU9 La clave del SII QU9 no está en la retícula del alumno en el SII, pero llegó a AEF-1001, que está en su plan de Nexus: cuenta en Nexus y no en el SII. 1 renglones de la historia.',
        ])
        ->and(verificationIncidents($reports, 'error'))->each->not->toContain('QU9');
});

it('resume el conteo por diagnóstico y, por estatus del SII, los completos en el SII y en Nexus, en las incidencias y en la consola', function () {
    ($this->archive)();
    $reports = runVerificationStep($this->dir);

    $summary = [
        'Se compararon 14 renglones de la historia del SII con el kárdex de Nexus (sii_verificacion.materias).',
        '«igual»: 3 renglones.',
        '«pendiente del plan: cuenta»: 1 renglones.',
        '«fuera de la retícula del plan: no cuenta»: 1 renglones.',
        '«otra clave del SII es la misma materia: cuenta una vez»: 2 renglones.',
        '«fuera de la retícula del SII, en el plan de Nexus: cuenta en Nexus»: 1 renglones.',
        '«créditos distintos»: 1 renglones.',
        '«alumno no está en Nexus»: 1 renglones.',
        '«no pasó: el mismo alumno tiene la misma materia de Nexus en el mismo periodo (se queda uno)»: 1 renglones.',
        '«no pasó: la clave del SII no está en ninguna retícula»: 1 renglones.',
        '«no pasó: sin explicar»: 2 renglones.',
        'Se compararon 4 alumnos del SII con su avance en Nexus (sii_verificacion.alumnos).',
        'Estatus ACT del SII: 1 alumnos, 0 completos en el SII, 0 completos en Nexus, 0 no están en Nexus (0 de retículas que no se migran).',
        'Estatus EGR del SII: 3 alumnos, 2 completos en el SII, 1 completos en Nexus, 1 no están en Nexus (0 de retículas que no se migran).',
        '1 alumnos incompletos en Nexus cuentan menos créditos en Nexus que en el SII.',
    ];

    expect(array_map(fn (string $line): string => substr($line, strlen('info resumen ')), array_values(array_filter(
        verificationIncidents($reports, 'info'),
        fn (string $line): bool => str_starts_with($line, 'info resumen '),
    ))))->toBe($summary)
        ->and([...$reports[0]->notes, ...$reports[1]->notes])->toContain(...$summary)
        ->and(implode("\n", [...$reports[0]->notes, ...$reports[1]->notes]))->not->toContain('S2')
        ->and(array_map(fn (SiiBatchReport $report): array => [$report->template, $report->file, $report->rows, $report->valid, $report->warnings, $report->errors, $report->created, $report->changed], $reports))->toBe([
            ['sii_verificacion', 'materias', 14, 10, 2, 2, 14, 0],
            ['sii_verificacion', 'alumnos', 4, 3, 0, 1, 4, 0],
        ]);
});

it('recalcula el avance antes de comparar, sin esperar a la cola, y dice cuánto tardó', function () {
    StudentProgress::factory()->create([
        'student_id' => Student::query()->where('control_number', 'S2')->value('id'),
        'study_plan_id' => StudyPlan::query()->where('code', 'AAA-2010-224')->value('id'),
        'credits_approved' => 10,
    ]);
    ($this->archive)();

    $reports = runVerificationStep($this->dir);

    expect((int) StudentProgress::query()->whereRelation('student', 'control_number', 'S2')->value('credits_approved'))->toBe(5)
        ->and((int) StudentProgress::query()->whereRelation('student', 'control_number', 'S1')->value('credits_approved'))->toBe(18)
        ->and(DB::table('sii_verificacion.alumnos')->where('no_de_control', 'S2')->value('completo_nexus'))->toBeFalse()
        ->and(preg_grep('/^info avance El avance de los alumnos se recalculó antes de comparar: tardó \d+(\.\d+)? s\.$/', verificationIncidents($reports, 'info')))->toHaveCount(1);
});

it('una segunda corrida reemplaza los resultados', function () {
    ($this->archive)();
    runVerificationStep($this->dir);

    $this->history = [['20251', 'S1', 'CD1', 'A']];
    $this->students = [['S1', 'A/1', 'EGR']];
    ($this->archive)();
    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->pluck('diagnostico', '_linea')->all())->toBe([2 => VerificationDiagnosis::Same->value])
        ->and(DB::table('sii_verificacion.alumnos')->pluck('no_de_control')->all())->toBe(['S1']);
});

it('no verifica y dice por qué si falta algo de la corrida', function (bool $dryRun, array $done, bool $withHistory, string $reason) {
    ($this->archive)();

    if (! $withHistory) {
        unlink("{$this->dir}/historia_alumno.csv");
    }

    $reports = runVerificationStep($this->dir, $dryRun, $done);

    expect($reports)->toHaveCount(1)
        ->and([$reports[0]->template, $reports[0]->file, $reports[0]->rows, $reports[0]->errors])->toBe(['sii_verificacion', 'sin verificar', 0, 0])
        ->and(verificationIncidents($reports))->toBe(["warning verificacion No se verificó la migración contra el SII: {$reason}"])
        ->and($reports[0]->notes)->toBe(["No se verificó la migración contra el SII: {$reason}"]);

    if ($dryRun) {
        expect(DB::table('pg_tables')->where('schemaname', 'sii_verificacion')->exists())->toBeFalse();
    } else {
        expect(DB::table('sii_verificacion._corrida')->get(['carpeta', 'estado', 'razon'])->map(fn (object $row): array => (array) $row)->all())->toBe([
            ['carpeta' => $this->dir, 'estado' => 'no verificada', 'razon' => $reason],
        ])
            ->and(DB::table('sii_verificacion.materias')->count())->toBe(0)
            ->and(DB::table('sii_verificacion.alumnos')->count())->toBe(0);
    }
})->with([
    'en seco' => [true, ['archived', 'kardex_applied'], true, 'la corrida es un ensayo (--dry-run) y nada se escribió.'],
    'sin el archivo de esta corrida' => [false, ['kardex_applied'], true, 'el paso archivo no corrió en esta corrida, así que sii_archivo no es esta exportación (corre la migración completa o agrega --only=archivo).'],
    'sin historia en la exportación' => [false, ['archived'], false, 'la exportación no trae historia_alumno: no hay kárdex del SII contra qué comparar.'],
    'sin el kárdex de esta corrida' => [false, ['archived'], true, 'el paso kardex no corrió en esta corrida, así que el kárdex de Nexus no es el de esta exportación (agrega --only=kardex).'],
]);

it('anota en _corrida la carpeta, cuándo y si la corrida quedó verificada o con diferencias', function () {
    ($this->archive)();
    runVerificationStep($this->dir);

    $run = DB::table('sii_verificacion._corrida')->sole();

    expect([$run->carpeta, $run->estado, $run->razon])->toBe([$this->dir, 'con diferencias', '3 renglones o alumnos no cuadran: errores del paso verificacion en _incidencias.csv.'])
        ->and($run->verificado_en)->not->toBeNull();

    $this->history = [['20251', 'S1', 'CD1', 'A']];
    ($this->archive)();
    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion._corrida')->get(['estado', 'razon'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['estado' => 'verificada', 'razon' => null],
    ]);
});

it('si una corrida real se salta la verificación, vacía los resultados de la anterior', function () {
    ($this->archive)();
    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->count())->toBe(14);

    runVerificationStep($this->dir, done: ['kardex_applied']);

    expect(DB::table('sii_verificacion.materias')->count())->toBe(0)
        ->and(DB::table('sii_verificacion.alumnos')->count())->toBe(0)
        ->and(DB::table('sii_verificacion._corrida')->value('estado'))->toBe('no verificada');
});

it('da error por el alumno completo en el SII que no está en Nexus si su retícula se migra; si no se migra, solo lo cuenta', function () {
    $this->students = [['S9', 'A/1', 'EGR'], ['S8', 'A/3', 'EGR']];
    $this->totals = [['A/1', '10'], ['A/3', '8']];
    $this->history = [['20251', 'S9', 'CD1', 'A'], ['20253', 'S9', 'QU1', 'A'], ['20251', 'S8', 'OU1', 'A'], ['20253', 'S8', 'QU9', 'A']];
    ($this->archive)();

    $reports = runVerificationStep($this->dir);

    $students = DB::table('sii_verificacion.alumnos')->get()->keyBy('no_de_control');

    expect([$students['S9']->completo_sii, $students['S9']->en_nexus, $students['S9']->reticula_migrada])->toBe([true, false, true])
        ->and([$students['S8']->completo_sii, $students['S8']->en_nexus, $students['S8']->reticula_migrada])->toBe([true, false, false])
        ->and(verificationIncidents($reports, 'error'))->toBe([
            'error S9 Completo en el SII (10 de 10 créditos de la retícula A/1), que se migra, y no está en Nexus.',
        ])
        ->and($reports[1]->notes)->toContain('Estatus EGR del SII: 2 alumnos, 2 completos en el SII, 0 completos en Nexus, 2 no están en Nexus (1 de retículas que no se migran).');
});

it('un estatus de la historia distinto de A, R y P no pasa al kárdex: es «sin explicar» antes que cualquier otro diagnóstico', function () {
    $this->history = [['20251', 'S1', 'CD1', 'A'], ['20251', 'S1', 'CD1', 'X']];
    ($this->archive)();

    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->orderBy('_linea')->pluck('diagnostico', '_linea')->all())->toBe([
        2 => VerificationDiagnosis::Same->value,
        3 => VerificationDiagnosis::Unexplained->value,
    ]);
});

it('de los renglones que caen en la misma fila del kárdex queda uno, como en el paso kardex: la aprobada, la de mayor calificación y la última', function () {
    $this->history = [
        ['19001', 'S1', 'PE1', 'A', '80'],   // 2: dos periodos que no existen, misma fila sin periodo
        ['0', 'S1', 'PE1', 'A', '90'],       // 3: gana por calificación
        ['20251', 'S1', 'CD1', 'R', '60'],   // 4
        ['20251', 'S1', 'CD1', 'A', '70'],   // 5: gana por aprobada
        ['20251', 'S1', 'CD1', 'R', '95'],   // 6
        ['20251', 'S1', 'QU1', 'A', ''],     // 7
        ['20251', 'S1', 'QU1', 'A', ''],     // 8: empate, gana la última
    ];
    ($this->archive)();

    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->orderBy('_linea')->pluck('diagnostico', '_linea')->all())->toBe([
        2 => VerificationDiagnosis::KeptOther->value,
        3 => VerificationDiagnosis::PlanPending->value,
        4 => VerificationDiagnosis::KeptOther->value,
        5 => VerificationDiagnosis::Same->value,
        6 => VerificationDiagnosis::KeptOther->value,
        7 => VerificationDiagnosis::KeptOther->value,
        8 => VerificationDiagnosis::Same->value,
    ]);
});

it('advierte por clave de lo aprobado en la retícula del alumno en el SII que en Nexus no cuenta', function () {
    $this->grid[] = ['A/1', 'OU2', '3'];
    $this->history[] = ['20253', 'S3', 'OU2', 'A'];
    ($this->kardex)('S3', 'OUT-0001', '20253', 'OU2');
    ($this->archive)();

    $reports = runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.materias')->where('_linea', 16)->value('diagnostico'))->toBe(VerificationDiagnosis::OutsidePlan->value)
        ->and(DB::table('sii_verificacion.materias')->where('_linea', 16)->value('en_reticula_sii'))->toBeTrue()
        ->and(array_values(preg_grep('/^warning OU/', verificationIncidents($reports, 'warning'))))->toBe([
            'warning OU2 La clave del SII OU2 está en la retícula del alumno en el SII y está aprobada, pero llegó a OUT-0001, que no está en la retícula de su plan en Nexus: cuenta en el SII y no en Nexus. 1 renglones de la historia.',
        ]);
});

it('de un alumno repetido en alumnos_todos queda el primero', function () {
    $this->students = [['S1', 'A/1', 'EGR'], ['S1', 'A/3', 'ACT']];
    $this->history = [['20251', 'S1', 'CD1', 'A']];
    ($this->archive)();

    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.alumnos')->get(['_linea', 'no_de_control', 'reticula_sii', 'estatus_sii'])->map(fn (object $row): array => (array) $row)->all())->toBe([
        ['_linea' => 2, 'no_de_control' => 'S1', 'reticula_sii' => 'A/1', 'estatus_sii' => 'EGR'],
    ])
        ->and(DB::table('sii_verificacion.materias')->value('reticula_sii'))->toBe('A/1');
});

it('toma los créditos totales de una retícula repetida en carreras como el paso carreras: solo un entero mayor que cero, y sin él no está completo', function () {
    $this->students = [['S1', 'A/1', 'EGR'], ['S8', 'A/3', 'EGR'], ['S7', 'A/4', 'EGR'], ['S6', 'A/5', 'EGR']];
    $this->totals = [['A/1', "''"], ['A/1', '260'], ['A/3', '0'], ['A/3', '260'], ['A/4', "''"], ['A/5', '0']];
    $this->history = [['20251', 'S1', 'CD1', 'A']];
    ($this->archive)();

    runVerificationStep($this->dir);

    expect(DB::table('sii_verificacion.alumnos')->orderBy('no_de_control')->get(['no_de_control', 'creditos_requeridos_sii', 'completo_sii'])
        ->map(fn (object $row): array => [$row->no_de_control, $row->creditos_requeridos_sii === null ? null : (int) $row->creditos_requeridos_sii, $row->completo_sii])->all())->toBe([
            ['S1', 260, false],
            ['S6', null, false],
            ['S7', null, false],
            ['S8', 260, false],
        ]);
});

it('de una clave repetida en una retícula del SII toma sus créditos mayores', function () {
    $this->grid = [['A/1', 'CD1', '5'], ['A/1', 'CD1', '7'], ['A/1', 'QU1', '5']];
    $this->history = [['20251', 'S1', 'CD1', 'A'], ['20251', 'S1', 'QU1', 'A']];
    ($this->archive)();

    runVerificationStep($this->dir);

    $row = DB::table('sii_verificacion.materias')->where('_linea', 2)->sole();

    expect((float) $row->creditos_sii)->toBe(7.0)
        ->and($row->diagnostico)->toBe(VerificationDiagnosis::CreditsDiffer->value)
        ->and((float) DB::table('sii_verificacion.alumnos')->where('no_de_control', 'S1')->value('creditos_sii'))->toBe(12.0);
});
