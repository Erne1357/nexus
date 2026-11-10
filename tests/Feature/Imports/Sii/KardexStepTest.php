<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\Steps\KardexStep;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\File;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `kardex` de imports:migrate-sii: la historia del SII es el kárdex (K3: solo la historia en los periodos
| cerrados), con la oportunidad por tipo de evaluación (K1) y la aprobada con 0 sin calificación (K2); las materias
| del periodo en curso salen de la selección como «cursando». Datos sintéticos.
*/

/**
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>  $rows  periodo, número de control, materia, calificación, tipo de evaluación y estatus
 */
function writeSiiHistory(string $dir, array $rows): void
{
    SiiFixtures::write($dir.'/historia_alumno.csv', SiiFile::HistoriaAlumno, array_map(fn (array $r): string => SiiFixtures::line(SiiFile::HistoriaAlumno, [
        'periodo' => "'{$r[0]}'", 'no_de_control' => "'{$r[1]}'", 'materia' => "'{$r[2]}'", 'calificacion' => $r[3],
        'tipo_evaluacion' => "'{$r[4]}'", 'estatus_materia' => "'{$r[5]}'", 'usuario' => "'secreto'",
    ]), $rows));
}

/**
 * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $rows  periodo, número de control, materia, repetición y status
 */
function writeSiiSelection(string $dir, array $rows): void
{
    SiiFixtures::write($dir.'/seleccion_materias.csv', SiiFile::SeleccionMaterias, array_map(fn (array $r): string => SiiFixtures::line(SiiFile::SeleccionMaterias, [
        'periodo' => "'{$r[0]}'", 'no_de_control' => "'{$r[1]}'", 'materia' => "'{$r[2]}'", 'repeticion' => "'{$r[3]}'", 'status_seleccion' => "'{$r[4]}'",
        'calificacion' => '', 'tipo_evaluacion' => "''",
    ]), $rows));
}

/**
 * Sin `$withSiiCode` quita de cada fila la clave del SII, que solo revisan las pruebas que la necesitan.
 *
 * @param  array<string|int, string>  $subjectFor  clave del SII → clave de Nexus
 * @param  array<string, array<string|int, string>>  $subjectForPlan  plan → clave del SII → clave de Nexus
 * @return array{rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}
 */
function kardexStepRun(string $dir, array $subjectFor = ['CD1' => 'ACF-0901', 'CD2' => 'ACF-0901', 'QU1' => 'AEF-1001'], array $subjectForPlan = [], bool $withSiiCode = false): array
{
    $migration = new SiiMigration(SiiExport::open($dir), '/nonexistent');
    $migration->remember('subject_for', fn (): array => $subjectFor);
    $migration->remember('subject_for_plan', fn (): array => $subjectForPlan);
    $out = ['rows' => [], 'incidents' => []];

    foreach ((new KardexStep)->batches($migration) as $batch) {
        expect($batch->template)->toBe('kardex');
        $rows = array_values([...$batch->rows]);
        $out = ['rows' => $withSiiCode ? $rows : array_map(fn (array $row): array => array_diff_key($row, ['sii_subject_code' => true]), $rows), 'incidents' => $batch->incidents];
    }

    return $out;
}

/**
 * @param  list<array{level: string, ref: string, message: string}>  $incidents
 * @return list<string>
 */
function kardexMessages(array $incidents): array
{
    return array_map(fn (array $i): string => "{$i['ref']} {$i['message']}", $incidents);
}

function kardexStepRow(string $control, string $subject, string $term, int|string $grade, string $result, int $attempt): array
{
    return ['control_number' => $control, 'subject_code' => $subject, 'term_code' => $term, 'grade' => $grade, 'result' => $result, 'attempt' => $attempt];
}

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
    foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $controlNumber) {
        Student::factory()->create(['control_number' => $controlNumber]);
    }
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('convierte la historia en filas del kárdex de Nexus', function () {
    writeSiiHistory($this->dir, [
        ['20251', 'S1', 'CD1', '85', 'O1', 'A'],
        ['20253', 'S1', 'QU1', '0', 'R2', 'R'],
        ['20261', 'S1', 'QU1', '90', 'CE', 'A'],
        ['20043', 'S2', 'CD1', '0', 'E1', 'A'],
        ['20051', 'S2', 'QU1', '60', 'O1', 'P'],
    ]);

    expect(kardexStepRun($this->dir)['rows'])->toBe([
        kardexStepRow('S1', 'ACF-0901', '20251', 85, 'aprobada', 1),
        kardexStepRow('S1', 'AEF-1001', '20253', 0, 'reprobada', 2),
        kardexStepRow('S1', 'AEF-1001', '20261', 90, 'aprobada', 3),
        kardexStepRow('S2', 'ACF-0901', '20043', '', 'aprobada', 1),
        kardexStepRow('S2', 'AEF-1001', '20051', 60, 'reprobada', 1),
    ]);
});

it('no migra la materia sin equivalente en Nexus y avisa por clave del SII', function () {
    writeSiiHistory($this->dir, [['20251', 'S1', 'ZZZ', '85', 'O1', 'A'], ['20253', 'S2', 'ZZZ', '90', 'O1', 'A'], ['20251', 'S1', 'CD1', '85', 'O1', 'A']]);

    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toHaveCount(1)
        ->and(kardexMessages($run['incidents']))->toContain('ZZZ La materia ZZZ del SII no tiene equivalente en Nexus: 2 renglones de la historia no pasan al kárdex.');
});

it('deja una fila por alumno, materia de Nexus y periodo: la aprobada, la de mayor calificación y la última', function () {
    writeSiiHistory($this->dir, [
        ['20251', 'S1', 'CD1', '0', 'E1', 'A'],
        ['20251', 'S1', 'CD2', '80', 'R2', 'A'],
        ['20251', 'S2', 'CD1', '75', 'R1', 'A'],
        ['20251', 'S2', 'CD1', '50', 'O1', 'R'],
        ['20253', 'S3', 'CD1', '80', 'O1', 'A'],
        ['20253', 'S3', 'CD1', '80', 'O2', 'A'],
    ]);

    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toBe([
        kardexStepRow('S1', 'ACF-0901', '20251', 80, 'aprobada', 2),
        kardexStepRow('S2', 'ACF-0901', '20251', 75, 'aprobada', 1),
        kardexStepRow('S3', 'ACF-0901', '20253', 80, 'aprobada', 2),
    ])->and(kardexMessages($run['incidents']))->toContain('kardex 3 renglones de la historia repiten alumno, materia de Nexus y periodo: queda uno, la aprobada antes que la reprobada, luego la de mayor calificación y luego la última del archivo.');
});

it('avisa por línea, sin número de control, el periodo, el estatus y la calificación que no se entienden', function () {
    writeSiiHistory($this->dir, [
        ['19013', 'S1', 'CD1', '85', 'O1', 'A'],
        ['20251', 'S1', 'CD1', '85', 'O1', 'X'],
        ['20253', 'S1', 'CD1', 'NA', 'O1', 'A'],
        ['20261', '', 'CD1', '85', 'O1', 'A'],
    ]);
    writeSiiSelection($this->dir, []);

    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toBe([kardexStepRow('S1', 'ACF-0901', '', 85, 'aprobada', 1), kardexStepRow('S1', 'ACF-0901', '20253', '', 'aprobada', 1)])
        ->and(kardexMessages($run['incidents']))->toBe([
            'historia_alumno.csv:2 El periodo «19013» de esta línea no es un periodo del SII (año de 1964 al próximo y 1, 2 o 3): pasa al kárdex sin periodo, para que Servicios Escolares capture el bueno.',
            'historia_alumno.csv:3 El estatus de la materia de esta línea no es A, R ni P: no pasa al kárdex.',
            'historia_alumno.csv:4 La calificación de esta línea no se entiende: pasa al kárdex sin calificación.',
            'historia_alumno.csv:5 Esta línea no trae número de control: no pasa al kárdex.',
        ])
        ->and(json_encode($run))->not->toContain('secreto');
});

it('el periodo que no existe de un alumno que no está en Nexus no se anota por línea: ese renglón no pasa', function () {
    writeSiiHistory($this->dir, [['0', 'NOESTA', 'CD1', '85', 'O1', 'A'], ['0', 'S1', 'ZZZ', '85', 'O1', 'A']]);
    writeSiiSelection($this->dir, []);

    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toBe([])
        ->and(implode("\n", kardexMessages($run['incidents'])))->not->toContain('sin periodo');
});

it('dos renglones de la misma materia con periodos que no existen quedan en uno sin periodo: el aprobado', function () {
    writeSiiHistory($this->dir, [['0', 'S1', 'CD1', '85', 'O1', 'A'], ['19001', 'S1', 'CD1', '50', 'O1', 'R']]);
    writeSiiSelection($this->dir, []);

    expect(kardexStepRun($this->dir)['rows'])->toBe([kardexStepRow('S1', 'ACF-0901', '', 85, 'aprobada', 1)]);
});

it('un tipo de evaluación que no conoce cuenta como oportunidad 1 y avisa por código', function () {
    writeSiiHistory($this->dir, [['20251', 'S1', 'CD1', '85', '8', 'A'], ['20251', 'S2', 'CD1', '', '', 'A'], ['20253', 'S3', 'CD1', '', '', 'A']]);

    $run = kardexStepRun($this->dir);

    expect(array_column($run['rows'], 'attempt'))->toBe([1, 1, 1])
        ->and(kardexMessages($run['incidents']))->toContain(
            'kardex 1 renglones de la historia traen el tipo de evaluación «8», que no se conoce: cuentan como oportunidad 1.',
            'kardex 2 renglones de la historia no traen tipo de evaluación: cuentan como oportunidad 1.',
        );
});

it('las materias del periodo en curso salen de la selección como cursando', function () {
    writeSiiHistory($this->dir, [
        ['20253', 'S1', 'CD1', '85', 'O1', 'A'],
        ['20263', 'S4', 'QU1', '90', 'RC', 'A'],
    ]);
    writeSiiSelection($this->dir, [
        ['20253', 'S1', 'CD1', 'N', 'I'],
        ['20253', 'S2', 'CD1', 'N', 'I'],
        ['20263', 'S1', 'QU1', 'N', 'I'],
        ['20263', 'S2', 'QU1', 'S', 'I'],
        ['20263', 'S3', 'QU1', 'N', 'S'],
        ['20263', 'S4', 'QU1', 'N', 'I'],
        ['20263', 'S5', 'ZZZ', 'N', 'I'],
    ]);

    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toBe([
        kardexStepRow('S1', 'ACF-0901', '20253', 85, 'aprobada', 1),
        kardexStepRow('S4', 'AEF-1001', '20263', 90, 'aprobada', 1),
        kardexStepRow('S1', 'AEF-1001', '20263', '', 'cursando', 1),
        kardexStepRow('S2', 'AEF-1001', '20263', '', 'cursando', 2),
    ])->and(kardexMessages($run['incidents']))->toContain(
        'kardex 1 renglones de seleccion_materias de periodos cerrados no están en la historia: no pasan al kárdex, que en esos periodos sale solo de la historia.',
        'kardex 1 renglones de la selección de 20263 no están inscritos (status_seleccion distinto de I): no pasan al kárdex.',
        'kardex 1 renglones de la selección de 20263 ya están en la historia: queda el de la historia.',
        'ZZZ La materia ZZZ del SII no tiene equivalente en Nexus: 1 renglones de la selección de 20263 no pasan al kárdex.',
    );
});

it('si todos los periodos de la selección ya pasaron a la historia, no hay materias en curso', function () {
    writeSiiHistory($this->dir, [['20263', 'S1', 'CD1', '85', 'O1', 'A'], ['20263', 'S2', 'CD1', '90', 'O1', 'A']]);
    writeSiiSelection($this->dir, [['20263', 'S1', 'CD1', 'N', 'I'], ['20263', 'S2', 'CD1', 'N', 'I'], ['20263', 'S3', 'CD1', 'N', 'I']]);

    $run = kardexStepRun($this->dir);

    expect(array_column($run['rows'], 'result'))->toBe(['aprobada', 'aprobada'])
        ->and(kardexMessages($run['incidents']))->toContain(
            'kardex Todos los periodos de seleccion_materias ya están en la historia: ninguna materia pasa como cursando.',
            'kardex 1 renglones de seleccion_materias de periodos cerrados no están en la historia: no pasan al kárdex, que en esos periodos sale solo de la historia.',
        );
});

it('pasan como cursando los dos periodos en curso: el que corre y el siguiente ya seleccionado', function () {
    writeSiiHistory($this->dir, [['20261', 'S1', 'CD1', '85', 'O1', 'A']]);
    writeSiiSelection($this->dir, [
        ['20261', 'S1', 'CD1', 'N', 'I'],
        ['20263', 'S1', 'QU1', 'N', 'I'],
        ['20271', 'S1', 'CD2', 'N', 'I'],
    ]);

    expect(kardexStepRun($this->dir, ['CD1' => 'ACF-0901', 'CD2' => 'ACF-0902', 'QU1' => 'AEF-1001'])['rows'])->toBe([
        kardexStepRow('S1', 'ACF-0901', '20261', 85, 'aprobada', 1),
        kardexStepRow('S1', 'AEF-1001', '20263', '', 'cursando', 1),
        kardexStepRow('S1', 'ACF-0902', '20271', '', 'cursando', 1),
    ]);
});

it('no pasan los renglones de alumnos que no están en Nexus y se cuentan sin número de control', function () {
    writeSiiHistory($this->dir, [['20251', 'NOEXISTE1', 'CD1', '85', 'O1', 'A'], ['20251', 'S1', 'CD1', '85', 'O1', 'A']]);
    writeSiiSelection($this->dir, [['20263', 'NOEXISTE2', 'QU1', 'N', 'I'], ['20263', 'S1', 'QU1', 'N', 'I']]);

    $run = kardexStepRun($this->dir);

    expect(array_column($run['rows'], 'control_number'))->toBe(['S1', 'S1'])
        ->and(kardexMessages($run['incidents']))->toContain(
            'kardex 1 renglones de la historia son de alumnos que no están en Nexus: no pasan al kárdex.',
            'kardex 1 renglones de la selección de 20263 son de alumnos que no están en Nexus: no pasan al kárdex.',
        )
        ->and(json_encode($run['incidents']))->not->toContain('NOEXISTE');
});

it('en la selección repetida por dos claves del SII queda la oportunidad mayor', function () {
    writeSiiSelection($this->dir, [['20263', 'S1', 'CD1', 'N', 'I'], ['20263', 'S1', 'CD2', 'S', 'I']]);
    writeSiiHistory($this->dir, []);

    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toBe([kardexStepRow('S1', 'ACF-0901', '20263', '', 'cursando', 2)])
        ->and(kardexMessages($run['incidents']))->toContain('kardex 1 renglones de la selección de 20263 repiten alumno, materia de Nexus y periodo: pasa uno, con la oportunidad mayor.');
});

it('se detiene si dos renglones distintos chocan en la misma llave, en vez de perder uno', function () {
    writeSiiHistory($this->dir, [['20251', 'S1', 'CD1', '85', 'O1', 'A'], ['20251', 'S2', 'CD1', '85', 'O1', 'A']]);
    $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');
    $migration->remember('subject_for', fn (): array => ['CD1' => 'ACF-0901']);
    $migration->remember('subject_for_plan', fn (): array => []);

    expect(fn () => iterator_to_array((new KardexStep(fn (string $tuple): int => 42))->batches($migration)))
        ->toThrow(RuntimeException::class, 'chocaron en la misma llave');
});

it('lleva la clave del SII a la materia de la retícula del alumno y guarda la clave original', function () {
    $plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224']);
    Student::factory()->for($plan)->create(['control_number' => 'S6', 'career_id' => $plan->career_id]);
    writeSiiHistory($this->dir, [['20221', 'S6', '3BE', '80', 'O1', 'A'], ['20221', 'S1', '3BE', '90', 'O1', 'A']]);
    writeSiiSelection($this->dir, [['20263', 'S6', '3BE', 'N', 'I']]);

    $run = kardexStepRun($this->dir, ['3BE' => 'AEC-1008'], ['ISIC-2010-224' => ['3BE' => '3EB', '3EB' => '3EB']], withSiiCode: true);

    expect(array_map(fn (array $row): array => [$row['control_number'], $row['subject_code'], $row['sii_subject_code']], $run['rows']))->toBe([
        ['S6', '3EB', '3BE'],
        ['S1', 'AEC-1008', '3BE'],
        ['S6', '3EB', '3BE'],
    ]);
});

it('sin historia no migra el kárdex y lo avisa', function () {
    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toBe([])
        ->and(kardexMessages($run['incidents']))->toBe(['kardex La exportación no trae historia_alumno: el kárdex no se migra.']);
});

it('sin selección migra la historia y avisa que no hay materias en curso', function () {
    writeSiiHistory($this->dir, [['20253', 'S1', 'CD1', '85', 'O1', 'A']]);

    $run = kardexStepRun($this->dir);

    expect($run['rows'])->toHaveCount(1)
        ->and(kardexMessages($run['incidents']))->toBe(['kardex La exportación no trae seleccion_materias: ninguna materia del periodo en curso pasa como cursando.']);
});

it('solo lee las columnas que usa: ni el usuario ni las fechas', function () {
    expect(KardexStep::HISTORY_COLUMNS)->toEqualCanonicalizing(['periodo', 'no_de_control', 'materia', 'calificacion', 'tipo_evaluacion', 'estatus_materia'])
        ->and(KardexStep::SELECTION_COLUMNS)->toEqualCanonicalizing(['periodo', 'no_de_control', 'materia', 'repeticion', 'status_seleccion']);
});

it('el paso de materias debe correr antes, también su mapa por plan', function () {
    writeSiiHistory($this->dir, [['20251', 'S1', 'CD1', '85', 'O1', 'A']]);
    $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');

    expect(fn () => iterator_to_array((new KardexStep)->batches($migration)))->toThrow(SiiStepOutOfOrder::class);

    $migration->remember('subject_for', fn (): array => ['CD1' => 'ACF-0901']);

    expect(fn () => iterator_to_array((new KardexStep)->batches($migration)))->toThrow(SiiStepOutOfOrder::class, 'subject_for_plan');
});
